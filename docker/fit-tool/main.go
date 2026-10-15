// Command fit-tool decodes a Garmin .FIT file and writes its contents to
// stdout as JSON. It is a thin wrapper around github.com/muktihari/fit so the
// PHP application can decode FIT files through a Symfony Process call.
//
// Usage:
//
//	fit-tool <path-to-fit-file>
//
// A gzip-compressed file (.fit.gz) is unzipped before decoding, so the caller
// does not need to know which of the two it was handed.
//
// Output shape:
//
//	{
//	  "files": [
//	    {
//	      "profileVersion": 2132,
//	      "messages": [
//	        {"name": "record", "num": 20, "fields": [
//	          {"name": "position_lat", "num": 0, "units": "semicircles", "value": 533590487},
//	          {"name": "speed", "num": 6, "units": "m/s", "value": 4.123}
//	        ]}
//	      ]
//	    }
//	  ]
//	}
//
// Field values already have their FIT scale/offset applied. Invalid/unset
// numeric fields (encoded as NaN by the FIT profile) are emitted as null so the
// document is always valid JSON. A single .FIT file may contain several chained
// FIT files, hence "files" is an array.
//
// On success it prints the JSON document and exits 0. On any error it prints a
// message to stderr and exits non-zero.
package main

import (
	"bytes"
	"compress/gzip"
	"encoding/binary"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"math"
	"os"

	"github.com/muktihari/fit/decoder"
	"github.com/muktihari/fit/kit/hash/crc16"
	"github.com/muktihari/fit/kit/scaleoffset"
	"github.com/muktihari/fit/profile/basetype"
	"github.com/muktihari/fit/proto"
)

type outFile struct {
	ProfileVersion uint16       `json:"profileVersion"`
	Messages       []outMessage `json:"messages"`
}

type outMessage struct {
	Name   string     `json:"name"`
	Num    uint16     `json:"num"`
	Fields []outField `json:"fields"`
}

type outField struct {
	Name  string `json:"name"`
	Num   byte   `json:"num"`
	Units string `json:"units,omitempty"`
	Value any    `json:"value"`
}

func main() {
	if len(os.Args) != 2 {
		fmt.Fprintln(os.Stderr, "usage: fit-tool <path-to-fit-file>")
		os.Exit(2)
	}

	if err := run(os.Args[1]); err != nil {
		fmt.Fprintf(os.Stderr, "fit-tool: %v\n", err)
		os.Exit(1)
	}
}

func run(path string) error {
	raw, err := os.ReadFile(path)
	if err != nil {
		return fmt.Errorf("open file: %w", err)
	}

	raw, err = decompress(raw)
	if err != nil {
		return err
	}

	files, err := decode(raw)
	// A bad CRC almost always means a quirky-but-readable file rather than
	// corrupt data, so retry while ignoring the checksum instead of failing.
	if errors.Is(err, decoder.ErrCRCChecksumMismatch) {
		fmt.Fprintf(os.Stderr, "fit-tool: warning: %v; retrying without checksum validation\n", err)
		files, err = decode(raw, decoder.WithIgnoreChecksum())
	}
	if err != nil {
		// Last resort for files that encode a field's base type without the FIT
		// endian-ability bit, which the decoder rejects outright. Repairing those
		// ids rewrites bytes, so the repaired copy is decoded without validating
		// the checksum the file carries for the original ones.
		repaired := bytes.Clone(raw)
		if !normalizeFIT(repaired) {
			return err
		}
		fmt.Fprintf(os.Stderr, "fit-tool: warning: %v; retrying with repaired field base types\n", err)
		// Keep the original error as the cause when even the repaired copy fails to
		// decode: it is the one describing the bytes the user is holding.
		repairedFiles, repairErr := decode(repaired, decoder.WithIgnoreChecksum())
		if repairErr != nil {
			return errors.Join(err, fmt.Errorf("after repairing field base types: %w", repairErr))
		}
		files = repairedFiles
	}

	enc := json.NewEncoder(os.Stdout)
	if err := enc.Encode(map[string]any{"files": files}); err != nil {
		return fmt.Errorf("encode json: %w", err)
	}

	return nil
}

// decompress returns the FIT bytes behind b. A .fit.gz file is unzipped here so
// the rest of the tool only ever sees FIT bytes: the gzip magic cannot be
// confused with a FIT header, whose first byte is the header size and whose
// second is a protocol version no device numbers 135. Chain-compressed members
// are read as one stream by the reader itself.
func decompress(b []byte) ([]byte, error) {
	if !bytes.HasPrefix(b, []byte{0x1f, 0x8b}) {
		return b, nil
	}

	zr, err := gzip.NewReader(bytes.NewReader(b))
	if err != nil {
		return nil, fmt.Errorf("read gzip: %w", err)
	}
	defer func() {
		_ = zr.Close()
	}()

	out := new(bytes.Buffer)
	if _, err := io.Copy(out, zr); err != nil {
		return nil, fmt.Errorf("unzip: %w", err)
	}

	return out.Bytes(), nil
}

// decode reads every chained FIT file in b into the output shape. The opts are
// forwarded to the decoder so callers can, e.g., relax CRC validation.
func decode(b []byte, opts ...decoder.Option) ([]outFile, error) {
	dec := decoder.New(bytes.NewReader(b), opts...)

	files := make([]outFile, 0, 1)
	// A .FIT file may contain multiple chained FIT files; decode them all.
	for dec.Next() {
		fit, err := dec.Decode()
		if err != nil {
			return nil, fmt.Errorf("decode: %w", err)
		}

		file := outFile{
			ProfileVersion: fit.FileHeader.ProfileVersion,
			Messages:       make([]outMessage, 0, len(fit.Messages)),
		}

		for _, mesg := range fit.Messages {
			fields := make([]outField, 0, len(mesg.Fields))
			for _, field := range mesg.Fields {
				if field.FieldBase == nil {
					continue
				}

				fields = append(fields, convertField(field))
			}

			file.Messages = append(file.Messages, outMessage{
				Name:   mesg.Num.String(),
				Num:    uint16(mesg.Num),
				Fields: fields,
			})
		}

		files = append(files, file)
	}

	return files, nil
}

// normalizeFIT repairs the base type ids of every message definition in the
// chained FIT files held in b, in place, and reports whether it changed
// anything. Each rewritten definition changes the bytes a checksum covers, so
// the checksums of the files that were touched are recalculated.
//
// A field definition stores the base type number in bits 0-4 of its base type id
// and the endian-ability flag in bit 7, which the FIT profile sets for every type
// wider than one byte. The decoder looks the raw byte up in its base type table
// instead of masking the number out, so a file that writes a bare type number
// (0x04 for uint16 rather than 0x84) is rejected as an invalid base type even
// though the field width and byte order are both unambiguous. Some recorders,
// the Xiaomi Watch S5 among them, write files that way.
//
// Only ids the decoder would reject are rewritten, so a conformant file comes
// out byte for byte identical. Anything the walk cannot account for stops it
// rather than guessing at where the next record starts, which keeps a file that
// decodes today decodable tomorrow.
func normalizeFIT(b []byte) bool {
	var changed bool
	for pos := 0; pos+12 <= len(b); {
		headerSize := int(b[pos])
		if (headerSize != 12 && headerSize != 14) || string(b[pos+8:pos+12]) != ".FIT" {
			return changed
		}

		dataSize := int(binary.LittleEndian.Uint32(b[pos+4 : pos+8]))
		end := pos + headerSize + dataSize
		if dataSize == 0 || end+2 > len(b) {
			return changed
		}

		data := b[pos+headerSize : end]
		if normalizeRecords(data) {
			// A repaired definition changes the bytes the trailing checksum covers,
			// which is the data section, so recalculate it. The header keeps its own
			// checksum because the walk never touches it.
			checksum := crc16.New()
			_, _ = checksum.Write(data)
			binary.LittleEndian.PutUint16(b[end:end+2], checksum.Sum16())
			changed = true
		}

		pos = end + 2
	}

	return changed
}

// normalizeRecords walks the data section of a single FIT file, repairing base
// type ids as it goes, and reports whether it changed anything. It has to follow
// every record to find the definitions, which means it also has to know how wide
// each data message is, so it tracks the size of every local message type the
// definitions it passes declare.
func normalizeRecords(data []byte) bool {
	var (
		sizes   [16]int
		defined [16]bool
		changed bool
	)

	for pos := 0; pos < len(data); {
		header := data[pos]
		pos++

		switch {
		case header&proto.MesgCompressedHeaderMask == proto.MesgCompressedHeaderMask:
			// A compressed timestamp header holds its local message type in bits 6-5
			// and its time offset in bits 0-4, so such a record is one header byte
			// followed by the fields the definition declares, like any other.
			local := (header & proto.CompressedLocalMesgNumMask) >> proto.CompressedBitShift
			if !defined[local] {
				return changed
			}
			pos += sizes[local]
		case header&proto.MesgDefinitionMask == proto.MesgDefinitionMask:
			if pos+5 > len(data) {
				return changed
			}
			local := header & proto.LocalMesgNumMask
			end := pos + 5 + int(data[pos+4])*3
			if end > len(data) {
				return changed
			}

			size := 0
			for field := pos + 5; field < end; field += 3 {
				if baseType, ok := repairBaseType(data[field+2]); ok {
					data[field+2] = baseType
					changed = true
				}
				size += int(data[field+1])
			}
			pos = end

			if header&proto.DevDataMask == proto.DevDataMask {
				// A developer field definition holds a field number, a size and a
				// developer data index. That third byte is not a base type, so these
				// add to the width of the data messages without being repaired.
				if pos+1 > len(data) {
					return changed
				}
				end := pos + 1 + int(data[pos])*3
				if end > len(data) {
					return changed
				}
				for field := pos + 1; field < end; field += 3 {
					size += int(data[field+1])
				}
				pos = end
			}

			sizes[local] = size
			defined[local] = true
		default:
			local := header & proto.LocalMesgNumMask
			if !defined[local] {
				return changed
			}
			pos += sizes[local]
		}
	}

	return changed
}

// repairBaseType returns id with the endian-ability bit set, but only when that
// missing bit is the sole reason the decoder would reject it. Ids already in the
// decoder's base type table, whether single-byte ids or ids already carrying the
// bit, come back untouched.
func repairBaseType(id byte) (byte, bool) {
	if basetype.BaseType(id).Valid() {
		return id, false
	}

	candidate := basetype.BaseType(id | basetype.EndianAbilityMask)
	if candidate.Valid() && candidate.Size() > 1 {
		return byte(candidate), true
	}

	return id, false
}

// convertField turns a decoded FIT field into its JSON output form. Fields
// holding the FIT "no value" sentinel are emitted as null; otherwise the
// raw value has its scale/offset applied and is sanitized.
func convertField(field proto.Field) outField {
	out := outField{
		Name:  field.Name,
		Num:   field.Num,
		Units: field.Units,
	}

	raw := field.Value.Any()
	if isInvalid(raw, field.BaseType) {
		out.Value = nil
		return out
	}

	scale := field.Scale
	if scale == 0 {
		scale = 1
	}

	out.Value = sanitize(scaleoffset.ApplyAny(raw, scale, field.Offset))

	return out
}

// isInvalid reports whether raw is the FIT "no value" sentinel for its base
// type. Integer and byte fields encode "unset" as a max-value sentinel (e.g.
// uint16 -> 0xFFFF = 65535) which must be nulled out before scale/offset turns
// it into a plausible-looking number. Float sentinels decode to NaN and are
// handled by sanitize instead, so they are treated as valid here.
func isInvalid(raw any, bt basetype.BaseType) bool {
	invalid := bt.Invalid()
	switch invalid.(type) {
	case float32, float64:
		return false
	}

	return raw == invalid
}

// sanitize replaces non-finite floats (the FIT "invalid" sentinel) with nil so
// the result is always valid JSON, recursing into float slices. Byte slices
// (e.g. the hr message's filtered_bpm samples) are widened to number slices
// because encoding/json would otherwise emit []uint8 as a base64 string.
func sanitize(v any) any {
	switch x := v.(type) {
	case float64:
		return finiteOrNil(x)
	case float32:
		return finiteOrNil(float64(x))
	case []uint8:
		out := make([]any, len(x))
		for i, e := range x {
			out[i] = e
		}
		return out
	case []float64:
		out := make([]any, len(x))
		for i, e := range x {
			out[i] = finiteOrNil(e)
		}
		return out
	case []float32:
		out := make([]any, len(x))
		for i, e := range x {
			out[i] = finiteOrNil(float64(e))
		}
		return out
	default:
		return v
	}
}

func finiteOrNil(f float64) any {
	if math.IsNaN(f) || math.IsInf(f, 0) {
		return nil
	}
	return f
}

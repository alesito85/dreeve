// Tests for the base type repair that lets files encoding their field types
// without the FIT endian-ability bit still decode. The fixtures in this
// repository are all conformant, so these build their own bytes instead.

package main

import (
	"bytes"
	"encoding/binary"
	"fmt"
	"strings"
	"testing"

	"github.com/muktihari/fit/kit/hash/crc16"
	"github.com/muktihari/fit/proto"
)

func TestNormalizeFITRepairsMissingEndianAbility(t *testing.T) {
	// file_id carrying its type as an enum and its manufacturer as a bare uint16
	// number: the shape a Xiaomi Watch S5 writes and the decoder refuses.
	broken := fitFile(t, mesgDefinition(0, 0,
		fieldDefinition(0, 1, 0x00), // type, enum, one byte, no bit to set
		fieldDefinition(1, 2, 0x04), // manufacturer, uint16 without the endian bit
	), dataMessage(0, 0x01, 0x01, 0x00))

	if _, err := decode(broken); err == nil || !strings.Contains(err.Error(), "invalid basetype") {
		t.Fatalf("decode of the unrepaired file: want an invalid basetype error, got %v", err)
	}

	repaired := bytes.Clone(broken)
	if !normalizeFIT(repaired) {
		t.Fatal("normalizeFIT: want the bare uint16 base type to be repaired")
	}
	dataEnd := len(repaired) - 2
	if got := repaired[dataEnd:]; !bytes.Equal(got, checksumOf(t, repaired[14:dataEnd])) {
		t.Error("normalizeFIT: want the trailing checksum recalculated over the data")
	}

	// Decoding without relaxing the checksum is deliberate: it only succeeds if
	// the recalculated checksum is the one the decoder expects.
	files, err := decode(repaired)
	if err != nil {
		t.Fatalf("decode after repair: %v", err)
	}
	if len(files) != 1 {
		t.Fatalf("decoded %d files, want 1", len(files))
	}
	if len(files[0].Messages) != 1 {
		t.Fatalf("decoded %d messages, want 1", len(files[0].Messages))
	}

	msg := files[0].Messages[0]
	if msg.Name != "file_id" {
		t.Errorf("decoded message %q, want file_id", msg.Name)
	}
	if got := fieldValue(t, msg, 1); got != "1" {
		t.Errorf("manufacturer: want 1 read little-endian, got %s", got)
	}
}

func TestNormalizeFITLeavesConformantFilesAlone(t *testing.T) {
	// The payload needs one byte per declared field size: 1 + 2 + 4 + 4 + 1.
	conformant := fitFile(t, mesgDefinition(0, 0,
		fieldDefinition(0, 1, 0x00), // type, enum
		fieldDefinition(1, 2, 0x84), // manufacturer, uint16 with the endian bit
		fieldDefinition(3, 4, 0x86), // time_created, uint32
		fieldDefinition(4, 4, 0x8C), // serial_number, uint32z
		fieldDefinition(5, 1, 0x0A), // product, uint8z: single byte, valid as is
	), dataMessage(0, 0x01, 0x01,
		0x00, 0x00, 0x00, 0x00, 0x00, // manufacturer high byte, time_created
		0x00, 0x00, 0x00, 0x00, // serial_number
		0x00, // product
	))

	before := bytes.Clone(conformant)
	if normalizeFIT(conformant) {
		t.Error("normalizeFIT: want nothing to change in a conformant file")
	}
	if !bytes.Equal(before, conformant) {
		t.Error("normalizeFIT: want a conformant file to stay byte for byte identical")
	}

	files, err := decode(conformant)
	if err != nil {
		t.Fatalf("decode: %v", err)
	}
	if len(files) != 1 || len(files[0].Messages) != 1 {
		t.Fatalf("decoded %+v, want one file_id message", files)
	}
}

func TestNormalizeFITRepairsAcrossCompressedTimestampData(t *testing.T) {
	// A record and a session definition that both write their timestamp as a bare
	// uint32, with the record's data stored under a compressed timestamp header
	// (bit 7 set, local message type in bits 6-5). The walk has to skip that whole
	// record to reach the definition behind it.
	record := mesgDefinition(0, 20, fieldDefinition(253, 4, 0x06))
	session := mesgDefinition(1, 18, fieldDefinition(253, 4, 0x06))
	b := fitFile(t, record,
		dataMessage(proto.MesgCompressedHeaderMask, 0x01, 0x02, 0x03, 0x04),
		session,
		dataMessage(1, 0x00, 0x00, 0x00, 0x00),
	)

	if !normalizeFIT(b) {
		t.Fatal("normalizeFIT: want both bare uint32 base types repaired")
	}
	if got := b[14+len(record)-1]; got != 0x86 {
		t.Errorf("record timestamp base type: want 0x86, got %#02x", got)
	}
	if got := b[14+len(record)+5+len(session)-1]; got != 0x86 {
		t.Errorf("session timestamp base type: want 0x86, got %#02x", got)
	}
	if got := b[14+len(record)]; got != proto.MesgCompressedHeaderMask {
		t.Errorf("the walk must not rewrite a data header: want 0x80, got %#02x", got)
	}
}

func TestNormalizeFITLeavesWhatItCannotParseAlone(t *testing.T) {
	definition := mesgDefinition(0, 0, fieldDefinition(0, 1, 0x04))

	tests := map[string][]byte{
		"not a FIT file": []byte("this is not a FIT file, it is a text file"),
		"truncated data": fitFile(t, definition)[:20],
		"no definition":  fitFile(t, dataMessage(0, 0x01, 0x02, 0x03)),
	}

	for name, b := range tests {
		t.Run(name, func(t *testing.T) {
			before := bytes.Clone(b)
			if normalizeFIT(b) {
				t.Error("normalizeFIT: want nothing reported as changed")
			}
			if !bytes.Equal(before, b) {
				t.Error("normalizeFIT: want the bytes left alone")
			}
		})
	}
}

func TestNormalizeFITRepairsEveryChainedFile(t *testing.T) {
	quirky := func() []byte {
		return fitFile(t, mesgDefinition(0, 0,
			fieldDefinition(0, 1, 0x00),
			fieldDefinition(1, 2, 0x04),
		), dataMessage(0, 0x01, 0x01, 0x00))
	}

	first, second := quirky(), quirky()
	broken := append(first, second...)
	if len(broken) != len(first)+len(second) {
		t.Fatal("building the chained file lost bytes")
	}

	if !normalizeFIT(broken) {
		t.Fatal("normalizeFIT: want the chained files repaired")
	}
	files, err := decode(broken)
	if err != nil {
		t.Fatalf("decode after repair: %v", err)
	}
	if len(files) != 2 {
		t.Fatalf("decoded %d files, want 2", len(files))
	}
}

// fitFile wraps the parts of a data section in the header and trailing checksum
// a decoder expects. The header checksum stays zero, which tells the decoder to
// skip it, and the data checksum is calculated.
func fitFile(t *testing.T, parts ...[]byte) []byte {
	t.Helper()

	var data []byte
	for _, part := range parts {
		data = append(data, part...)
	}

	b := make([]byte, 14+len(data)+2)
	b[0] = 14
	b[1] = 0x10 // protocol version 1.0
	binary.LittleEndian.PutUint16(b[2:4], 2132)
	binary.LittleEndian.PutUint32(b[4:8], uint32(len(data)))
	copy(b[8:12], ".FIT")
	copy(b[14:], data)
	copy(b[14+len(data):], checksumOf(t, data))

	return b
}

func checksumOf(t *testing.T, data []byte) []byte {
	t.Helper()

	checksum := crc16.New()
	if _, err := checksum.Write(data); err != nil {
		t.Fatalf("calculate checksum: %v", err)
	}

	return []byte{byte(checksum.Sum16()), byte(checksum.Sum16() >> 8)}
}

// mesgDefinition builds a message definition for mesgNum as local type local.
func mesgDefinition(local byte, mesgNum uint16, fields ...[3]byte) []byte {
	var num [2]byte
	binary.LittleEndian.PutUint16(num[:], mesgNum)

	b := append([]byte{proto.MesgDefinitionMask | local, 0x00, 0x00, num[0], num[1]}, byte(len(fields)))
	for _, f := range fields {
		b = append(b, f[0], f[1], f[2])
	}

	return b
}

func fieldDefinition(num, size, baseType byte) [3]byte {
	return [3]byte{num, size, baseType}
}

// dataMessage builds a normal header data message for local type local.
func dataMessage(local byte, fields ...byte) []byte {
	return append([]byte{local}, fields...)
}

func fieldValue(t *testing.T, msg outMessage, num byte) string {
	t.Helper()

	for _, f := range msg.Fields {
		if f.Num == num {
			return fmt.Sprintf("%v", f.Value)
		}
	}

	t.Fatalf("message %q has no field %d, it has %+v", msg.Name, num, msg.Fields)

	return ""
}

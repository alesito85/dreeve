<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261015120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the file checksum and device reference columns to Activity';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Activity ADD fileChecksum VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE Activity ADD deviceReferenceId VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE INDEX Activity_fileChecksum ON Activity (fileChecksum)');
        $this->addSql('CREATE INDEX Activity_deviceReferenceId ON Activity (deviceReferenceId, startDateTime)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX Activity_deviceReferenceId');
        $this->addSql('DROP INDEX Activity_fileChecksum');
        $this->addSql('ALTER TABLE Activity DROP COLUMN deviceReferenceId');
        $this->addSql('ALTER TABLE Activity DROP COLUMN fileChecksum');
    }
}

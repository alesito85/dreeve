<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261015180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the ActivityRevision table that keeps the state a merge replaced';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ActivityRevision (revisionId VARCHAR(255) NOT NULL, activityId VARCHAR(255) NOT NULL, revisionNumber INTEGER NOT NULL, summary CLOB NOT NULL, generatedByFileImportId VARCHAR(255) DEFAULT NULL, createdOn DATETIME NOT NULL, PRIMARY KEY (revisionId))');
        $this->addSql('CREATE INDEX ActivityRevision_activity ON ActivityRevision (activityId, revisionNumber)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX ActivityRevision_activity');
        $this->addSql('DROP TABLE ActivityRevision');
    }
}

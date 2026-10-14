<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014185706 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the cycling dynamics column to Activity';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Activity ADD dynamics CLOB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE Activity DROP COLUMN dynamics');
    }
}

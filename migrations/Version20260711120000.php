<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260711120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the WebhookEvent table, Strava webhook support has been removed.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE WebhookEvent');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE WebhookEvent (objectId VARCHAR(255) NOT NULL, objectType VARCHAR(255) NOT NULL, aspectType VARCHAR(255) NOT NULL, payload CLOB NOT NULL, PRIMARY KEY (objectId))');
    }
}

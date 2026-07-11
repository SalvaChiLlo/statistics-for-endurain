<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260711210821 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop Segment, SegmentEffort and Challenge tables: the Segment domain and Strava Challenges/Trophy Case feature have no Endurain data source and were removed.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE Challenge');
        $this->addSql('DROP TABLE Segment');
        $this->addSql('DROP TABLE SegmentEffort');
        $this->addSql(<<<'SQL'
            CREATE TEMPORARY TABLE __temp__ActivityBestEffort AS
            SELECT
              activityId,
              distanceInMeter,
              sportType,
              timeInSeconds
            FROM
              ActivityBestEffort
        SQL);
        $this->addSql('DROP TABLE ActivityBestEffort');
        $this->addSql(<<<'SQL'
            CREATE TABLE ActivityBestEffort (
              activityId VARCHAR(255) NOT NULL,
              distanceInMeter INTEGER NOT NULL,
              sportType VARCHAR(255) NOT NULL,
              timeInSeconds INTEGER NOT NULL,
              PRIMARY KEY (activityId, distanceInMeter)
            )
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO ActivityBestEffort (
              activityId, distanceInMeter, sportType,
              timeInSeconds
            )
            SELECT
              activityId,
              distanceInMeter,
              sportType,
              timeInSeconds
            FROM
              __temp__ActivityBestEffort
        SQL);
        $this->addSql('DROP TABLE __temp__ActivityBestEffort');
        $this->addSql('CREATE INDEX ActivityBestEffort_sportTypeIndex ON ActivityBestEffort (sportType)');
        $this->addSql(<<<'SQL'
            CREATE TEMPORARY TABLE __temp__ActivitySplit AS
            SELECT
              activityId,
              unitSystem,
              splitNumber,
              distance,
              elapsedTimeInSeconds,
              movingTimeInSeconds,
              elevationDifference,
              averageSpeed,
              minAverageSpeed,
              maxAverageSpeed,
              paceZone,
              gapPaceInSecondsPerKm
            FROM
              ActivitySplit
        SQL);
        $this->addSql('DROP TABLE ActivitySplit');
        $this->addSql(<<<'SQL'
            CREATE TABLE ActivitySplit (
              activityId VARCHAR(255) NOT NULL,
              unitSystem VARCHAR(255) NOT NULL,
              splitNumber INTEGER NOT NULL,
              distance INTEGER NOT NULL,
              elapsedTimeInSeconds INTEGER NOT NULL,
              movingTimeInSeconds INTEGER NOT NULL,
              elevationDifference INTEGER NOT NULL,
              averageSpeed DOUBLE PRECISION NOT NULL,
              minAverageSpeed DOUBLE PRECISION NOT NULL,
              maxAverageSpeed INTEGER NOT NULL,
              paceZone INTEGER NOT NULL,
              gapPaceInSecondsPerKm DOUBLE PRECISION DEFAULT NULL,
              PRIMARY KEY (
                activityId, unitSystem, splitNumber
              )
            )
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO ActivitySplit (
              activityId, unitSystem, splitNumber,
              distance, elapsedTimeInSeconds,
              movingTimeInSeconds, elevationDifference,
              averageSpeed, minAverageSpeed, maxAverageSpeed,
              paceZone, gapPaceInSecondsPerKm
            )
            SELECT
              activityId,
              unitSystem,
              splitNumber,
              distance,
              elapsedTimeInSeconds,
              movingTimeInSeconds,
              elevationDifference,
              averageSpeed,
              minAverageSpeed,
              maxAverageSpeed,
              paceZone,
              gapPaceInSecondsPerKm
            FROM
              __temp__ActivitySplit
        SQL);
        $this->addSql('DROP TABLE __temp__ActivitySplit');
        $this->addSql('CREATE INDEX ActivitySplit_activityIdUnitSystemIndex ON ActivitySplit (activityId, unitSystem)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE Challenge (
              challengeId VARCHAR(255) NOT NULL COLLATE "BINARY",
              createdOn DATETIME NOT NULL,
              name VARCHAR(255) NOT NULL COLLATE "BINARY",
              logoUrl VARCHAR(255) DEFAULT NULL COLLATE "BINARY",
              localLogoUrl VARCHAR(255) DEFAULT NULL COLLATE "BINARY",
              slug VARCHAR(255) NOT NULL COLLATE "BINARY",
              PRIMARY KEY (challengeId)
            )
        SQL);
        $this->addSql('CREATE INDEX Challenge_createdOnIndex ON Challenge (createdOn)');
        $this->addSql(<<<'SQL'
            CREATE TABLE Segment (
              segmentId VARCHAR(255) NOT NULL COLLATE "BINARY",
              name VARCHAR(255) DEFAULT NULL COLLATE "BINARY",
              sportType VARCHAR(255) NOT NULL COLLATE "BINARY",
              distance INTEGER NOT NULL,
              maxGradient DOUBLE PRECISION NOT NULL,
              isFavourite BOOLEAN NOT NULL,
              climbCategory INTEGER DEFAULT NULL,
              deviceName VARCHAR(255) DEFAULT NULL COLLATE "BINARY",
              countryCode VARCHAR(255) DEFAULT NULL COLLATE "BINARY",
              detailsHaveBeenImported BOOLEAN DEFAULT NULL,
              polyline CLOB DEFAULT NULL COLLATE "BINARY",
              startingCoordinateLatitude DOUBLE PRECISION DEFAULT NULL,
              startingCoordinateLongitude DOUBLE PRECISION DEFAULT NULL,
              averageGradient DOUBLE PRECISION DEFAULT NULL,
              PRIMARY KEY (segmentId)
            )
        SQL);
        $this->addSql('CREATE INDEX Segment_detailsHaveBeenImported ON Segment (detailsHaveBeenImported)');
        $this->addSql(<<<'SQL'
            CREATE TABLE SegmentEffort (
              segmentEffortId VARCHAR(255) NOT NULL COLLATE "BINARY",
              segmentId VARCHAR(255) NOT NULL COLLATE "BINARY",
              activityId VARCHAR(255) NOT NULL COLLATE "BINARY",
              startDateTime DATETIME NOT NULL,
              name VARCHAR(255) NOT NULL COLLATE "BINARY",
              elapsedTimeInSeconds DOUBLE PRECISION NOT NULL,
              distance INTEGER NOT NULL,
              averageWatts DOUBLE PRECISION DEFAULT NULL,
              averageHeartRate INTEGER DEFAULT NULL,
              maxHeartRate INTEGER DEFAULT NULL,
              PRIMARY KEY (segmentEffortId)
            )
        SQL);
        $this->addSql('CREATE INDEX SegmentEffort_segmentStartDateTime ON SegmentEffort (segmentId, startDateTime)');
        $this->addSql(<<<'SQL'
            CREATE INDEX SegmentEffort_segmentElapsedTime ON SegmentEffort (segmentId, elapsedTimeInSeconds)
        SQL);
        $this->addSql('CREATE INDEX SegmentEffort_activityIndex ON SegmentEffort (activityId)');
        $this->addSql('CREATE INDEX SegmentEffort_segmentIndex ON SegmentEffort (segmentId)');
        $this->addSql(<<<'SQL'
            CREATE TEMPORARY TABLE __temp__ActivityBestEffort AS
            SELECT
              activityId,
              distanceInMeter,
              sportType,
              timeInSeconds
            FROM
              ActivityBestEffort
        SQL);
        $this->addSql('DROP TABLE ActivityBestEffort');
        $this->addSql(<<<'SQL'
            CREATE TABLE ActivityBestEffort (
              activityId VARCHAR(255) NOT NULL,
              distanceInMeter INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              sportType VARCHAR(255) NOT NULL,
              timeInSeconds INTEGER NOT NULL
            )
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO ActivityBestEffort (
              activityId, distanceInMeter, sportType,
              timeInSeconds
            )
            SELECT
              activityId,
              distanceInMeter,
              sportType,
              timeInSeconds
            FROM
              __temp__ActivityBestEffort
        SQL);
        $this->addSql('DROP TABLE __temp__ActivityBestEffort');
        $this->addSql('CREATE INDEX ActivityBestEffort_sportTypeIndex ON ActivityBestEffort (sportType)');
        $this->addSql(<<<'SQL'
            CREATE TEMPORARY TABLE __temp__ActivitySplit AS
            SELECT
              activityId,
              unitSystem,
              splitNumber,
              distance,
              elapsedTimeInSeconds,
              movingTimeInSeconds,
              elevationDifference,
              averageSpeed,
              minAverageSpeed,
              maxAverageSpeed,
              paceZone,
              gapPaceInSecondsPerKm
            FROM
              ActivitySplit
        SQL);
        $this->addSql('DROP TABLE ActivitySplit');
        $this->addSql(<<<'SQL'
            CREATE TABLE ActivitySplit (
              activityId VARCHAR(255) NOT NULL,
              unitSystem VARCHAR(255) NOT NULL,
              splitNumber INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
              distance INTEGER NOT NULL,
              elapsedTimeInSeconds INTEGER NOT NULL,
              movingTimeInSeconds INTEGER NOT NULL,
              elevationDifference INTEGER NOT NULL,
              averageSpeed DOUBLE PRECISION NOT NULL,
              minAverageSpeed DOUBLE PRECISION NOT NULL,
              maxAverageSpeed INTEGER NOT NULL,
              paceZone INTEGER NOT NULL,
              gapPaceInSecondsPerKm DOUBLE PRECISION DEFAULT NULL
            )
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO ActivitySplit (
              activityId, unitSystem, splitNumber,
              distance, elapsedTimeInSeconds,
              movingTimeInSeconds, elevationDifference,
              averageSpeed, minAverageSpeed, maxAverageSpeed,
              paceZone, gapPaceInSecondsPerKm
            )
            SELECT
              activityId,
              unitSystem,
              splitNumber,
              distance,
              elapsedTimeInSeconds,
              movingTimeInSeconds,
              elevationDifference,
              averageSpeed,
              minAverageSpeed,
              maxAverageSpeed,
              paceZone,
              gapPaceInSecondsPerKm
            FROM
              __temp__ActivitySplit
        SQL);
        $this->addSql('DROP TABLE __temp__ActivitySplit');
        $this->addSql('CREATE INDEX ActivitySplit_activityIdUnitSystemIndex ON ActivitySplit (activityId, unitSystem)');
    }
}

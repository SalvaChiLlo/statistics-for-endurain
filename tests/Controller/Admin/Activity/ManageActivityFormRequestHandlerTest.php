<?php

namespace App\Tests\Controller\Admin\Activity;

use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ActivityWithRawData;
use App\Tests\Controller\Admin\AdminWebTestCase;
use App\Tests\Domain\Activity\ActivityBuilder;

class ManageActivityFormRequestHandlerTest extends AdminWebTestCase
{
    public function testAnonymousUsersAreRedirectedToTheLoginPage(): void
    {
        $this->client->request('GET', '/admin/activities/'.ActivityId::fromUnprefixed('1').'/edit');

        $this->assertResponseRedirects('/admin/login');
    }

    public function testAnonymousUsersAreRedirectedToTheLoginPageOnDelete(): void
    {
        $this->client->request('GET', '/admin/activities/'.ActivityId::fromUnprefixed('1').'/delete');

        $this->assertResponseRedirects('/admin/login');
    }

    public function testRendersTheEditFormPrefilledWithTheActivityWithFieldsEditableAndImagesShown(): void
    {
        // Strava API import mode has been removed: fields are never disabled and images
        // are always manageable now, regardless of import mode.
        static::getContainer()->get(ActivityRepository::class)->add(ActivityWithRawData::fromState(
            ActivityBuilder::fromDefaults()
                ->withActivityId(ActivityId::fromUnprefixed('1'))
                ->withName('Morning Run')
                ->withIsCommute(false)
                ->build(),
            [],
        ));

        $this->client->loginUser($this->adminUser());

        $crawler = $this->client->request('GET', '/admin/activities/'.ActivityId::fromUnprefixed('1').'/edit');

        $this->assertResponseIsSuccessful();

        $this->assertNull($crawler->filter('input#activity-name')->attr('disabled'));
        $this->assertNull($crawler->filter('select#activity-sport-type')->attr('disabled'));
        $this->assertNull($crawler->filter('select#activity-gear')->attr('disabled'));
        $this->assertNull($crawler->filter('select#activity-device-name')->attr('disabled'));
        $this->assertNull($crawler->filter('input#activity-is-commute')->attr('disabled'));

        $this->assertCount(0, $crawler->filter('input[type="hidden"][name="name"]'));
        $this->assertCount(0, $crawler->filter('input[type="hidden"][name="sportType"]'));
        $this->assertCount(0, $crawler->filter('input[type="hidden"][name="gearId"]'));
        $this->assertCount(0, $crawler->filter('input[type="hidden"][name="deviceName"]'));
        $this->assertSame('false', $crawler->filter('input[type="hidden"][name="isCommute"]')->attr('value'));

        // The image upload is available.
        $this->assertCount(1, $crawler->filter('[data-image-dropzone]'));
    }

    public function testRendersTheDeleteConfirmationForTheActivity(): void
    {
        static::getContainer()->get(ActivityRepository::class)->add(ActivityWithRawData::fromState(
            ActivityBuilder::fromDefaults()
                ->withActivityId(ActivityId::fromUnprefixed('1'))
                ->withName('Morning Run')
                ->build(),
            [],
        ));

        $this->client->loginUser($this->adminUser());

        $crawler = $this->client->request('GET', '/admin/activities/'.ActivityId::fromUnprefixed('1').'/delete');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Delete activity', $crawler->filter('h3')->text());
        $this->assertStringContainsString('Morning Run', $crawler->filter('body')->text());

        $form = $crawler->filter('form[data-dispatch-command="delete-activity"]');
        $this->assertCount(1, $form);

        $this->assertSame((string) ActivityId::fromUnprefixed('1'), $form->filter('input[name="activityId"]')->attr('value'));
    }
}

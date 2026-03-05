<?php

namespace Pterodactyl\Tests\Integration\Api\Client\Server\Files;

use Mockery\MockInterface;
use Pterodactyl\Models\Permission;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;

class FileAccessPathAuthorizationTest extends ClientApiIntegrationTestCase
{
    public function testSubuserCannotReadFileOutsideConfiguredPathRules(): void
    {
        [$user, $server] = $this->generateTestAccount([Permission::ACTION_FILE_READ_CONTENT]);

        $server->subusers()->where('user_id', $user->id)->update([
            'file_access' => [
                'read-content' => [
                    'allow' => ['^/allowed/'],
                    'deny' => ['^/allowed/private/'],
                ],
            ],
        ]);

        $this->mock(DaemonFileRepository::class, function (MockInterface $mock) {
            $mock->expects('setServer->getContent')
                ->with('/allowed/config.yml', config('pterodactyl.files.max_edit_size'))
                ->once()
                ->andReturn('test');
        });

        $this->actingAs($user)
            ->get($this->link($server, '/files/contents?file=/allowed/config.yml'))
            ->assertOk();

        $this->getJson($this->link($server, '/files/contents?file=/blocked.txt'))
            ->assertForbidden()
            ->assertJsonPath(
                'errors.0.detail',
                'You do not have permission to access one or more of the selected files or folders.'
            );

        $this->getJson($this->link($server, '/files/contents?file=/allowed/private/secret.txt'))
            ->assertForbidden();
    }
}

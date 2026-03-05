<?php

namespace Pterodactyl\Tests\Integration\Api\Client\Server\Subuser;

use Pterodactyl\Models\User;
use Pterodactyl\Models\Subuser;
use Pterodactyl\Models\Permission;
use Pterodactyl\Tests\Integration\Api\Client\ClientApiIntegrationTestCase;

class SubuserFileAccessTest extends ClientApiIntegrationTestCase
{
    public function testFileAccessRulesAreSavedForSubuser(): void
    {
        [$user, $server] = $this->generateTestAccount();

        $response = $this->actingAs($user)->postJson($this->link($server) . '/users', [
            'email' => $email = 'file-access-user@example.com',
            'permissions' => [
                Permission::ACTION_FILE_READ,
                Permission::ACTION_FILE_UPDATE,
            ],
            'file_access' => [
                'read' => [
                    'allow' => ['^/plugins/'],
                    'deny' => ['^/plugins/private/'],
                ],
                'update' => [
                    'allow' => ['^/plugins/config\\.yml$'],
                ],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('attributes.file_access.read.allow', ['^/plugins/']);
        $response->assertJsonPath('attributes.file_access.read.deny', ['^/plugins/private/']);
        $response->assertJsonPath('attributes.file_access.update.allow', ['^/plugins/config\\.yml$']);
        $response->assertJsonPath('attributes.file_access.update.deny', []);

        /** @var User $subuserUser */
        $subuserUser = User::query()->where('email', $email)->firstOrFail();
        /** @var Subuser $subuser */
        $subuser = Subuser::query()->where('server_id', $server->id)->where('user_id', $subuserUser->id)->firstOrFail();

        $this->assertSame([
            'read' => [
                'allow' => ['^/plugins/'],
                'deny' => ['^/plugins/private/'],
            ],
            'update' => [
                'allow' => ['^/plugins/config\\.yml$'],
                'deny' => [],
            ],
        ], $subuser->file_access);
    }

    public function testFileAccessRulesAreIgnoredForPermissionsNotAssignedToSubuser(): void
    {
        [$user, $server] = $this->generateTestAccount();

        $response = $this->actingAs($user)->postJson($this->link($server) . '/users', [
            'email' => $email = 'file-access-filtered@example.com',
            'permissions' => [
                Permission::ACTION_FILE_READ,
            ],
            'file_access' => [
                'read' => [
                    'allow' => ['^/logs/'],
                ],
                'update' => [
                    'allow' => ['^/logs/'],
                ],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('attributes.file_access.read.allow', ['^/logs/']);
        $this->assertArrayNotHasKey('update', $response->json('attributes.file_access'));

        /** @var User $subuserUser */
        $subuserUser = User::query()->where('email', $email)->firstOrFail();
        /** @var Subuser $subuser */
        $subuser = Subuser::query()->where('server_id', $server->id)->where('user_id', $subuserUser->id)->firstOrFail();

        $this->assertSame([
            'read' => [
                'allow' => ['^/logs/'],
                'deny' => [],
            ],
        ], $subuser->file_access);
    }
}

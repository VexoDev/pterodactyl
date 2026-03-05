<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Response;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Permission;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Services\Nodes\NodeJWTService;
use Pterodactyl\Services\Subusers\SubuserFileAccessService;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Transformers\Api\Client\FileObjectTransformer;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Http\Requests\Api\Client\Servers\Files\CopyFileRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Files\PullFileRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Files\ListFilesRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Files\ChmodFilesRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Files\DeleteFileRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Files\RenameFileRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Files\CreateFolderRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Files\CompressFilesRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Files\DecompressFilesRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Files\GetFileContentsRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Files\WriteFileContentRequest;

class FileController extends ClientApiController
{
    /**
     * FileController constructor.
     */
    public function __construct(
        private NodeJWTService $jwtService,
        private DaemonFileRepository $fileRepository,
        private SubuserFileAccessService $fileAccessService,
    ) {
        parent::__construct();
    }

    /**
     * Returns a listing of files in a given directory.
     *
     * @throws \Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException
     */
    public function directory(ListFilesRequest $request, Server $server): array
    {
        $directory = $request->get('directory') ?? '/';
        $this->fileAccessService->assertUserCanAccessPaths($server, $request->user(), Permission::ACTION_FILE_READ, [
            ['path' => $directory, 'directory' => true],
        ]);

        $contents = $this->fileRepository
            ->setServer($server)
            ->getDirectory($directory);

        return $this->fractal->collection($contents)
            ->transformWith($this->getTransformer(FileObjectTransformer::class))
            ->toArray();
    }

    /**
     * Return the contents of a specified file for the user.
     *
     * @throws \Throwable
     */
    public function contents(GetFileContentsRequest $request, Server $server): Response
    {
        $file = $request->get('file');
        $this->fileAccessService->assertUserCanAccessPaths($server, $request->user(), Permission::ACTION_FILE_READ_CONTENT, [$file]);

        $response = $this->fileRepository->setServer($server)->getContent(
            $file,
            config('pterodactyl.files.max_edit_size')
        );

        Activity::event('server:file.read')->property('file', $request->get('file'))->log();

        return new Response($response, Response::HTTP_OK, ['Content-Type' => 'text/plain']);
    }

    /**
     * Generates a one-time token with a link that the user can use to
     * download a given file.
     *
     * @throws \Throwable
     */
    public function download(GetFileContentsRequest $request, Server $server): array
    {
        $file = $request->get('file');
        $this->fileAccessService->assertUserCanAccessPaths($server, $request->user(), Permission::ACTION_FILE_READ_CONTENT, [$file]);

        $token = $this->jwtService
            ->setExpiresAt(CarbonImmutable::now()->addMinutes(15))
            ->setUser($request->user())
            ->setClaims([
                'file_path' => rawurldecode($file),
                'server_uuid' => $server->uuid,
            ])
            ->handle($server->node, $request->user()->id . $server->uuid);

        Activity::event('server:file.download')->property('file', $request->get('file'))->log();

        return [
            'object' => 'signed_url',
            'attributes' => [
                'url' => sprintf(
                    '%s/download/file?token=%s',
                    $server->node->getConnectionAddress(),
                    $token->toString()
                ),
            ],
        ];
    }

    /**
     * Writes the contents of the specified file to the server.
     *
     * @throws \Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException
     */
    public function write(WriteFileContentRequest $request, Server $server): JsonResponse
    {
        $file = $request->get('file');
        $this->fileAccessService->assertUserCanAccessPaths($server, $request->user(), Permission::ACTION_FILE_CREATE, [$file]);

        $this->fileRepository->setServer($server)->putContent($file, $request->getContent());

        Activity::event('server:file.write')->property('file', $file)->log();

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    /**
     * Creates a new folder on the server.
     *
     * @throws \Throwable
     */
    public function create(CreateFolderRequest $request, Server $server): JsonResponse
    {
        $root = $request->input('root') ?? '/';
        $name = $request->input('name');
        $this->fileAccessService->assertUserCanAccessPaths($server, $request->user(), Permission::ACTION_FILE_CREATE, [[
            'path' => $this->fileAccessService->joinPath($root, $name, true),
            'directory' => true,
        ]]);

        $this->fileRepository
            ->setServer($server)
            ->createDirectory($name, $root);

        Activity::event('server:file.create-directory')
            ->property('name', $name)
            ->property('directory', $root)
            ->log();

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    /**
     * Renames a file on the remote machine.
     *
     * @throws \Throwable
     */
    public function rename(RenameFileRequest $request, Server $server): JsonResponse
    {
        $root = $request->input('root');
        $files = $request->input('files');
        $this->fileAccessService->assertUserCanAccessPaths(
            $server,
            $request->user(),
            Permission::ACTION_FILE_UPDATE,
            $this->getPathsForRename($root, $files)
        );

        $this->fileRepository
            ->setServer($server)
            ->renameFiles($root, $files);

        Activity::event('server:file.rename')
            ->property('directory', $root)
            ->property('files', $files)
            ->log();

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    /**
     * Copies a file on the server.
     *
     * @throws \Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException
     */
    public function copy(CopyFileRequest $request, Server $server): JsonResponse
    {
        $location = $request->input('location');
        $this->fileAccessService->assertUserCanAccessPaths($server, $request->user(), Permission::ACTION_FILE_CREATE, [$location]);

        $this->fileRepository
            ->setServer($server)
            ->copyFile($location);

        Activity::event('server:file.copy')->property('file', $location)->log();

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    /**
     * @throws \Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException
     */
    public function compress(CompressFilesRequest $request, Server $server): array
    {
        $root = $request->input('root');
        $files = $request->input('files');
        $this->fileAccessService->assertUserCanAccessPaths(
            $server,
            $request->user(),
            Permission::ACTION_FILE_ARCHIVE,
            $this->getPathsForRootFiles($root, $files)
        );

        $file = $this->fileRepository->setServer($server)->compressFiles(
            $root,
            $files
        );

        Activity::event('server:file.compress')
            ->property('directory', $root)
            ->property('files', $files)
            ->log();

        return $this->fractal->item($file)
            ->transformWith($this->getTransformer(FileObjectTransformer::class))
            ->toArray();
    }

    /**
     * @throws \Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException
     */
    public function decompress(DecompressFilesRequest $request, Server $server): JsonResponse
    {
        set_time_limit(300);

        $root = $request->input('root');
        $file = $request->input('file');
        $this->fileAccessService->assertUserCanAccessPaths($server, $request->user(), Permission::ACTION_FILE_CREATE, [
            $this->fileAccessService->joinPath($root, $file),
        ]);

        $this->fileRepository->setServer($server)->decompressFile(
            $root,
            $file
        );

        Activity::event('server:file.decompress')
            ->property('directory', $root)
            ->property('files', $file)
            ->log();

        return new JsonResponse([], JsonResponse::HTTP_NO_CONTENT);
    }

    /**
     * Deletes files or folders for the server in the given root directory.
     *
     * @throws \Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException
     */
    public function delete(DeleteFileRequest $request, Server $server): JsonResponse
    {
        $root = $request->input('root');
        $files = $request->input('files');
        $this->fileAccessService->assertUserCanAccessPaths(
            $server,
            $request->user(),
            Permission::ACTION_FILE_DELETE,
            $this->getPathsForRootFiles($root, $files)
        );

        $this->fileRepository->setServer($server)->deleteFiles(
            $root,
            $files
        );

        Activity::event('server:file.delete')
            ->property('directory', $root)
            ->property('files', $files)
            ->log();

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    /**
     * Updates file permissions for file(s) in the given root directory.
     *
     * @throws \Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException
     */
    public function chmod(ChmodFilesRequest $request, Server $server): JsonResponse
    {
        $root = $request->input('root');
        $files = $request->input('files');
        $this->fileAccessService->assertUserCanAccessPaths(
            $server,
            $request->user(),
            Permission::ACTION_FILE_UPDATE,
            $this->getPathsForChmod($root, $files)
        );

        $this->fileRepository->setServer($server)->chmodFiles(
            $root,
            $files
        );

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    /**
     * Requests that a file be downloaded from a remote location by Wings.
     *
     * @throws \Throwable
     */
    public function pull(PullFileRequest $request, Server $server): JsonResponse
    {
        $directory = $request->input('directory') ?? '/';
        $this->fileAccessService->assertUserCanAccessPaths($server, $request->user(), Permission::ACTION_FILE_CREATE, [[
            'path' => $directory,
            'directory' => true,
        ]]);

        $this->fileRepository->setServer($server)->pull(
            $request->input('url'),
            $directory,
            $request->safe(['filename', 'use_header', 'foreground'])
        );

        Activity::event('server:file.pull')
            ->property('directory', $directory)
            ->property('url', $request->input('url'))
            ->log();

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    /**
     * @param mixed $files
     *
     * @return array<int, string|array{path: string, directory: bool}>
     */
    protected function getPathsForRootFiles(?string $root, $files): array
    {
        if (!is_array($files)) {
            return [];
        }

        $paths = [];
        foreach ($files as $file) {
            if (!is_string($file)) {
                continue;
            }

            $paths[] = $this->fileAccessService->joinPath($root, $file);
        }

        return $paths;
    }

    /**
     * @param mixed $files
     *
     * @return array<int, array{path: string, directory: bool}>
     */
    protected function getPathsForRename(?string $root, $files): array
    {
        if (!is_array($files)) {
            return [];
        }

        $paths = [];
        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }

            foreach (['from', 'to'] as $key) {
                $value = $file[$key] ?? null;
                if (!is_string($value)) {
                    continue;
                }

                $paths[] = [
                    'path' => $this->fileAccessService->joinPath($root, $value),
                    'directory' => str_ends_with(trim($value), '/'),
                ];
            }
        }

        return $paths;
    }

    /**
     * @param mixed $files
     *
     * @return array<int, string>
     */
    protected function getPathsForChmod(?string $root, $files): array
    {
        if (!is_array($files)) {
            return [];
        }

        $paths = [];
        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }

            $name = $file['file'] ?? null;
            if (!is_string($name)) {
                continue;
            }

            $paths[] = $this->fileAccessService->joinPath($root, $name);
        }

        return $paths;
    }
}

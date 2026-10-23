<?php
require '/var/www/mediawiki/maintenance/Maintenance.php';

class CheckS3Configuration extends MediaWiki\Maintenance\Maintenance {
    public function execute() {
        $config = $this->getConfig();
        $db = $config->get('DBname');
        $repo = MediaWiki\MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo();
        if ($config->get('AWSBucketName') !== 'cloudwiki01' || $config->get('AWSRegion') !== 'us-east-2') {
            throw new RuntimeException('Wrong S3 bucket or region');
        }
        $prefix = trim(getenv('MW_S3_PREFIX') ?: '', '/');
        $root = ($prefix === '' ? '' : $prefix . '/') . $db;
        if ($config->get('AWSBucketTopSubdirectory') !== '/' . $root ||
            $config->get('AWSRepoHashLevels') !== 2 || $config->get('AWSRepoDeletedHashLevels') !== 3) {
            throw new RuntimeException('Wrong wiki prefix or file hashes');
        }
        foreach (['public', 'thumb'] as $zone) {
            if ($repo->getZoneUrl($zone) !== '/img_auth.php' . ($zone === 'thumb' ? '/thumb' : '')) {
                throw new RuntimeException('S3 files bypass image authorization: ' . $zone);
            }
        }
        $backend = $repo->getBackend();
        if (!$backend instanceof AmazonS3FileBackend) {
            throw new RuntimeException('S3 backend not active');
        }
        $method = new ReflectionMethod($backend, 'getClient');
        $client = $method->invoke($backend);
        $client->getHandlerList()->setHandler(new Aws\MockHandler([
            new Aws\Result(), new Aws\Result(), new Aws\Result(), new Aws\Result(),
        ]));
        $commands = [];
        $client->getHandlerList()->appendInit(Aws\Middleware::mapCommand(
            static function ($command) use (&$commands) {
                $commands[] = $command->toArray();
                return $command;
            }
        ));
        $path = $repo->getZonePath('public') . '/s3-configuration-test.txt';
        $create = new ReflectionMethod($backend, 'doCreateInternal');
        $status = $create->invoke($backend, ['dst' => $path, 'content' => 'mocked upload']);
        if (!$status->isOK() || !$commands) {
            throw new RuntimeException('Mock S3 upload failed: ' . json_encode($status->getErrors()));
        }
        foreach ($commands as $command) {
            if (isset($command['ACL']) || ($command['Bucket'] ?? '') !== 'cloudwiki01' ||
                !str_starts_with($command['Key'] ?? '', $root . '/')) {
                throw new RuntimeException('Upload ACL, bucket or wiki prefix is incorrect');
            }
        }
        $avatars = MediaWiki\MediaWikiServices::getInstance()->getFileBackendGroup()->get('S3Avatars');
        $avatarClient = $method->invoke($avatars);
        $avatarClient->getHandlerList()->setHandler(new Aws\MockHandler([new Aws\Result()]));
        $avatarCommands = [];
        $avatarClient->getHandlerList()->appendInit(Aws\Middleware::mapCommand(
            static function ($command) use (&$avatarCommands) {
                $avatarCommands[] = $command->toArray();
                return $command;
            }
        ));
        $avatarPath = $avatars->getContainerStoragePath('upv2avatars') . '/mock-avatar.txt';
        $status = $create->invoke($avatars, ['dst' => $avatarPath, 'content' => 'mocked avatar']);
        $command = $avatarCommands[0] ?? [];
        if (!$status->isOK() || isset($command['ACL']) ||
            ($command['Bucket'] ?? '') !== 'cloudwiki01' ||
            ($command['Key'] ?? '') !== $root . '/upv2avatars/mock-avatar.txt' ||
            ($config->get('ImgAuthUrlPathMap')['/upv2avatars/'] ?? '') !== 'mwstore://S3Avatars/upv2avatars/') {
            throw new RuntimeException('Avatar bucket, wiki isolation or authenticated route is incorrect');
        }
        $this->output("S3 bucket, region, wiki isolation, private URLs and mocked upload verified for $db.\n");
    }
}
return CheckS3Configuration::class;

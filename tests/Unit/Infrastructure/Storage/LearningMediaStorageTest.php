<?php

declare(strict_types=1);

namespace Academy\Tests\Unit\Infrastructure\Storage;

use Academy\Application\Learning\LearningMediaIngestService;
use Academy\Domain\Courses\LearningMediaPolicy;
use Academy\Domain\Exception\ValidationException;
use Academy\Infrastructure\Storage\AwsSigV4Signer;
use Academy\Infrastructure\Storage\LearningLocalObjectStorage;
use Academy\Infrastructure\Storage\LearningMediaStorage;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class LearningMediaStorageTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/academy-learning-media-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0770, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testLocalStoreRoundTripUsesPrivatePrefixAndSignedUrl(): void
    {
        $inner = new LearningLocalObjectStorage($this->root, 'test-signing-secret');
        $storage = new LearningMediaStorage($inner);
        $ingest = new LearningMediaIngestService($storage, new LearningMediaPolicy());

        $stored = $ingest->store('pdf', "%PDF-1.4\n%", 'notes.pdf');
        self::assertStringStartsWith('learning/media/', $stored['object_key']);
        self::assertSame('application/pdf', $stored['media_mime']);
        self::assertSame('notes.pdf', $stored['original_filename']);
        self::assertFileDoesNotExist($this->root . '/../public/' . $stored['object_key']);

        $expires = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+15 minutes');
        $url = $storage->issueDownloadUrl($stored['object_key'], $expires);
        self::assertStringContainsString('/__local-storage/learning/download', $url['download_url']);
        self::assertStringNotContainsString('/public/', $url['download_url']);

        parse_str((string) parse_url($url['download_url'], PHP_URL_QUERY), $query);
        self::assertTrue($inner->verifySignedUrl(
            (string) $query['key'],
            (int) $query['exp'],
            (string) $query['sig'],
        ));
    }

    public function testDocumentKeyIsRejectedByLearningStore(): void
    {
        $storage = new LearningMediaStorage(new LearningLocalObjectStorage($this->root, 'test-signing-secret'));
        $this->expectException(ValidationException::class);
        $storage->putObject('applications/1/file.pdf', '%PDF', 'application/pdf');
    }

    public function testDownloadTtlOutsideWindowIsRejected(): void
    {
        $storage = new LearningMediaStorage(new LearningLocalObjectStorage($this->root, 'test-signing-secret'));
        $this->expectException(ValidationException::class);
        $storage->issueDownloadUrl(
            'learning/media/abc',
            (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+1 hour'),
        );
    }

    public function testSigV4PresignContainsRequiredQuery(): void
    {
        $signer = new AwsSigV4Signer('AKIAEXAMPLE', 'secret', 'ap-south-1');
        $url = $signer->presign(
            'GET',
            '/learning/media/abc',
            'bucket.s3.ap-south-1.amazonaws.com',
            new DateTimeImmutable('2026-09-13T08:00:00Z'),
            900,
        );
        self::assertStringContainsString('X-Amz-Algorithm=AWS4-HMAC-SHA256', $url);
        self::assertStringContainsString('X-Amz-Expires=900', $url);
        self::assertStringContainsString('X-Amz-Signature=', $url);
        self::assertStringStartsWith('https://bucket.s3.ap-south-1.amazonaws.com/learning/media/abc?', $url);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path . '/' . $item;
            if (is_dir($child)) {
                $this->removeTree($child);
            } else {
                unlink($child);
            }
        }
        rmdir($path);
    }
}

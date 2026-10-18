<?php

declare(strict_types=1);

namespace CPSIT\MyraApiEmulator;

use RuntimeException;

/**
 * One emulated answer: status, content type (null = none), body (string = raw, array = JSON).
 */
final readonly class Answer
{
    /**
     * @param array<mixed>|string $body
     */
    public function __construct(
        public int $status,
        public ?string $contentType,
        public array|string $body,
    ) {}

    public static function fixture(string $name): self
    {
        $json = file_get_contents(Paths::fixtures() . '/' . $name . '.json');
        $capture = json_decode($json === false ? '' : $json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($capture) || !is_array($capture['response'] ?? null)) {
            throw new RuntimeException('Broken fixture ' . $name);
        }
        $response = $capture['response'];
        $status = $response['status'] ?? null;
        $contentType = $response['contentType'] ?? null;
        $body = $response['body'] ?? null;

        return new self(
            is_int($status) ? $status : 500,
            is_string($contentType) ? $contentType : null,
            is_array($body) || is_string($body) ? $body : '',
        );
    }

    /**
     * The fixture's list, cut to one page.
     */
    /**
     * @param list<array<string, mixed>> $more items after the fixture's (the account's)
     */
    public static function page(string $name, string $key, int $page, array $more = []): self
    {
        $answer = self::fixture($name);
        $body = is_array($answer->body) ? $answer->body : [];
        $items = [...(is_array($body[$key] ?? null) ? array_values($body[$key]) : []), ...$more];
        $size = max(1, (int) Emulator::env('MYRA_EMULATOR_PAGE_SIZE', '50'));
        $body[$key] = array_slice($items, ($page - 1) * $size, $size);
        $body['page'] = $page;
        $body['count'] = count($items);
        $body['pageSize'] = $size;

        return new self(200, 'application/json', $body);
    }

    /**
     * @param array<mixed> $content
     */
    public static function success(string $key, array $content): self
    {
        return new self(200, 'application/json', ['error' => false, 'violationList' => [], 'warningList' => [], $key => $content]);
    }

    public function rename(string $from, string $to): self
    {
        $body = is_array($this->body) ? $this->body : [];
        $body[$to] = $body[$from] ?? [];
        unset($body[$from]);

        return new self($this->status, $this->contentType, $body);
    }

    public function send(): void
    {
        http_response_code($this->status);
        if ($this->contentType !== null) {
            header('Content-Type: ' . $this->contentType);
        }
        echo is_array($this->body) ? json_encode($this->body, JSON_UNESCAPED_SLASHES) : $this->body;
    }
}

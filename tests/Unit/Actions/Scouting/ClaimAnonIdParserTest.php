<?php

namespace Tests\Unit\Actions\Scouting;

use App\Actions\Scouting\ClaimAnonIdParser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ClaimAnonIdParserTest extends TestCase
{
    private ClaimAnonIdParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new ClaimAnonIdParser();
    }

    public function test_parses_primary_and_legacy_ids(): void
    {
        $result = $this->parser->parse('anon-primary', 'legacy-b, legacy-c');

        $this->assertTrue($result['ok']);
        $this->assertSame(['anon-primary', 'legacy-b', 'legacy-c'], $result['ids']);
    }

    public function test_rejects_too_many_legacy_ids(): void
    {
        $result = $this->parser->parse(
            'anon-primary',
            'a,b,c,d,e,f',
        );

        $this->assertFalse($result['ok']);
    }

    #[DataProvider('invalidIdProvider')]
    public function test_rejects_invalid_ids(string $primary, string $legacy): void
    {
        $result = $this->parser->parse($primary, $legacy);

        $this->assertFalse($result['ok']);
    }

    public static function invalidIdProvider(): array
    {
        return [
            'empty primary' => ['', ''],
            'comma in id' => ['bad,id', ''],
            'overlong id' => [str_repeat('a', 129), ''],
        ];
    }
}

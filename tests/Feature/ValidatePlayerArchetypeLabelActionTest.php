<?php

namespace Tests\Feature;

use App\Actions\Players\ValidatePlayerArchetypeLabelAction;
use App\Models\Player;
use Tests\TestCase;
use InvalidArgumentException;

class ValidatePlayerArchetypeLabelActionTest extends TestCase
{
    public function test_valid_label_passes_validation(): void
    {
        $player = new Player();
        $player->setAttribute('effective_name', 'David Raya');
        $player->setAttribute('club', 'Arsenal FC');

        $result = app(ValidatePlayerArchetypeLabelAction::class)
            ->execute('Dynamic Sweeper Keeper', $player);

        $this->assertSame('Dynamic Sweeper Keeper', $result);
    }

    public function test_quotes_and_dot_are_removed(): void
    {
        $player = new Player();

        $result = app(ValidatePlayerArchetypeLabelAction::class)
            ->execute('"Dynamic Sweeper Keeper."', $player);

        $this->assertSame('Dynamic Sweeper Keeper', $result);
    }

    public function test_empty_label_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Archetype label is empty.');

        app(ValidatePlayerArchetypeLabelAction::class)
            ->execute('', new Player());
    }

    public function test_label_with_more_than_four_words_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Archetype label has more than 4 words.');

        app(ValidatePlayerArchetypeLabelAction::class)
            ->execute('Very Dynamic Modern Sweeper Keeper', new Player());
    }

    public function test_label_containing_player_name_is_rejected(): void
    {
        $player = new Player();
        $player->setRawAttributes(['name' => 'David Raya']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Archetype label contains player name.');

        app(ValidatePlayerArchetypeLabelAction::class)
            ->execute('David Raya The Wall', $player);
    }

    public function test_label_containing_club_name_is_rejected(): void
    {
        $player = new Player();
        $player->setRawAttributes(['club' => 'Arsenal FC']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Archetype label contains club name.');

        app(ValidatePlayerArchetypeLabelAction::class)
            ->execute('Arsenal FC Sweeper Keeper', $player);
    }
}

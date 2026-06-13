<?php

namespace App\Actions;

class BuildPlayerArchetypePromptAction
{
    public function execute(array $inputSnapshot): array
    {
        $attributes = collect($inputSnapshot['attributes'])
            ->map(fn ($attribute) => "- {$attribute['label']}: {$attribute['rating']}, group: {$attribute['group']}, confidence: {$attribute['confidence']}")
            ->implode("\n");

        return [
            'system' => 'You generate concise scouting archetype labels for football players. Return exactly one short label. Maximum 4 words. No explanation. No quotes. No punctuation at the end. Do not include the player name. Do not include the club name. Avoid generic labels like Good Player or Talented Player. Avoid copying Football Manager-style labels. Use a professional but memorable scouting tone. Infer the player style from the full attribute profile.',
            'user' => "Generate one scouting archetype label for this player profile.\n\nPlayer:\nPosition: {$inputSnapshot['player']['position']}\nOverall: {$inputSnapshot['player']['overall']}\n\nAttributes:\n{$attributes}\n\nOutput:\nOne label, max 4 words.",
        ];
    }
}

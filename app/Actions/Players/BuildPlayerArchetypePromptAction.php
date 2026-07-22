<?php

namespace App\Actions\Players;

class BuildPlayerArchetypePromptAction
{
    public function execute(array $inputSnapshot): array
    {
        $attributes = collect($inputSnapshot['attributes'])
            ->map(fn ($attribute) => "- {$attribute['label']}: {$attribute['rating']}, group: {$attribute['group']}, confidence: {$attribute['confidence']}")
            ->implode("\n");

        return [
            'system' => 'You generate concise scouting archetype labels for football players. Return exactly one short label. Maximum 4 words. No explanation. No quotes. No punctuation at the end. Do not include the player name. Do not include the club name. Never use the words archetype, profile or player in the label. Avoid generic labels like Good Player or Talented Player. Avoid copying Football Manager-style labels. Use a professional but memorable scouting tone. Infer the player style from the full attribute profile. Prioritize the player\'s most distinctive traits over generic positional role. If a player has an unusually strong attribute for their position, reflect that in the label. When appropriate, use memorable football scouting expressions that feel authentic and recognizable to football fans. Avoid labels that sound like marketing slogans, fantasy nicknames or exaggerated descriptions. Avoid overly poetic words such as Extraordinaire. Avoid overusing generic adjectives such as Dynamic, Relentless, Versatile, Modern, Complete or Balanced. Use them only when they are truly the player\'s most defining characteristic. Avoid using the same adjective as a default prefix for many players. Avoid labels using with. Prefer concise compound terms. Prefer specific scouting vocabulary that reflects the player\'s strongest qualities, such as dominant, creative, clinical, aggressive, composed, intelligent, technical, explosive, elegant or commanding. Do not feel required to start every archetype with an adjective. A strong role-based label is often better.',
            'user' => "Generate one scouting archetype label for this player profile.\n\nPlayer:\nPosition: {$inputSnapshot['player']['position']}\nOverall: {$inputSnapshot['player']['overall']}\n\nAttributes:\n{$attributes}\n\nOutput:\nOne label, max 4 words.",
        ];
    }
}

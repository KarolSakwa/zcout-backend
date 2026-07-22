<?php

namespace App\Console\Commands\DataSync;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class MapPlayersToFplElementIds extends Command
{
    protected $signature = 'zcout:map-players-fpl {--limit=0} {--dry-run}';
    protected $description = 'Map players to FPL element IDs using FPL bootstrap-static (name + team)';

    public function handle(): int
    {
        $res = Http::timeout(25)->get('https://fantasy.premierleague.com/api/bootstrap-static/');
        if (!$res->ok()) {
            $this->error("FPL request failed: status={$res->status()}");
            $this->line(substr($res->body(), 0, 800));
            return self::FAILURE;
        }

        $elements = $res->json('elements');
        $teams = $res->json('teams');

        if (!is_array($elements) || !is_array($teams)) {
            $this->error('FPL response missing elements/teams');
            return self::FAILURE;
        }

        $elementsByTeam = [];
        foreach ($elements as $e) {
            if (!is_array($e) || !isset($e['id'], $e['team'])) continue;
            $tid = (int) $e['team'];
            $elementsByTeam[$tid][] = $e;
        }

        $teamsNorm = [];
        foreach ($teams as $t) {
            if (!is_array($t) || !isset($t['id'], $t['name'])) continue;
            $teamsNorm[] = [
                'id' => (int) $t['id'],
                'name' => (string) ($t['name'] ?? ''),
                'short_name' => (string) ($t['short_name'] ?? ''),
                'norm_name' => $this->norm((string) ($t['name'] ?? '')),
                'norm_short' => $this->norm((string) ($t['short_name'] ?? '')),
            ];
        }

        $existingEids = DB::table('players')
            ->whereNotNull('fpl_element_id')
            ->pluck('fpl_element_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        $existingEids = array_fill_keys($existingEids, true);
        $seenEids = [];

        $q = DB::table('players as p')
            ->leftJoin('clubs as c', 'c.id', '=', 'p.club_id')
            ->leftJoin('positions as pos', 'pos.id', '=', 'p.position_id')
            ->select([
                'p.id',
                'p.name',
                'p.fpl_element_id',
                DB::raw('COALESCE(c.name, p.club) as club_name'),
                DB::raw('pos.short_label as pos_short'),
            ])
            ->whereNull('p.fpl_element_id')
            ->orderBy('p.id');

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $q->limit($limit);
        }

        $rows = $q->get();
        if ($rows->isEmpty()) {
            $this->info('No players to map');
            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        $updated = 0;
        $written = 0;
        $ambiguous = 0;
        $notFound = 0;
        $missingTeam = 0;
        $eidTaken = 0;
        $missingRow = 0;

        foreach ($rows as $p) {
            $playerId = (int) $p->id;
            $playerName = (string) $p->name;
            $clubName = (string) ($p->club_name ?? '');

            $teamIds = $this->matchTeamIds($clubName, $teamsNorm);

            if (empty($teamIds)) {
                $missingTeam++;
                continue;
            }

            $match = $this->matchElement($playerName, $teamIds, $elementsByTeam, $p->pos_short ?? null);

            if ($match === null) {
                $notFound++;
                continue;
            }

            if (is_array($match) && isset($match['ambiguous']) && $match['ambiguous'] === true) {
                $ambiguous++;
                continue;
            }

            $eid = (int) $match;

            if (isset($seenEids[$eid])) {
                $ambiguous++;
                continue;
            }
            $seenEids[$eid] = true;

            if (isset($existingEids[$eid])) {
                $eidTaken++;
                continue;
            }

            $updated++;

            if (!$dryRun) {
                $affected = DB::table('players')->where('id', $playerId)->update(['fpl_element_id' => $eid]);
                if ($affected === 1) {
                    $written++;
                    $existingEids[$eid] = true;
                } else {
                    $missingRow++;
                }
            }
        }

        $this->info(
            "updated={$updated} written={$written} ambiguous={$ambiguous} notFound={$notFound} missingTeam={$missingTeam} eidTaken={$eidTaken} missingRow={$missingRow} dryRun=" . ($dryRun ? '1' : '0')
        );

        return self::SUCCESS;
    }

    private function matchElement(string $playerName, array $teamIds, array $elementsByTeam, ?string $posShort = null)
    {
        $targetTokens = $this->tokens($playerName);
        $targetFull = implode(' ', $targetTokens);
        $targetLast = $targetTokens ? $targetTokens[count($targetTokens) - 1] : '';

        $wantType = $this->guessFplTypeFromPosShort($posShort);

        $candidates = [];

        foreach ($teamIds as $tid) {
            $list = $elementsByTeam[(int) $tid] ?? [];
            foreach ($list as $e) {
                if (!is_array($e) || !isset($e['id'])) continue;

                $full = $this->norm(($e['first_name'] ?? '') . ' ' . ($e['second_name'] ?? ''));
                $web = $this->norm((string) ($e['web_name'] ?? ''));

                $fullTokens = $this->tokens($full);
                $webTokens = $this->tokens($web);

                $score = 0;

                if ($full === $targetFull) $score += 1200;
                if ($web === $targetFull) $score += 1000;

                $score += (int) round(400 * $this->overlap($targetTokens, $fullTokens));
                $score += (int) round(320 * $this->overlap($targetTokens, $webTokens));

                $candLast = $fullTokens ? $fullTokens[count($fullTokens) - 1] : '';
                if ($targetLast !== '' && $candLast !== '') {
                    if ($candLast === $targetLast) {
                        $score += 220;
                    } else {
                        $dist = levenshtein($targetLast, $candLast);
                        $bonus = 120 - (20 * $dist);
                        if ($bonus > 0) $score += $bonus;
                    }
                }

                $et = isset($e['element_type']) ? (int) $e['element_type'] : null;
                if ($wantType !== null && $et !== null && $et === $wantType) {
                    $score += 80;
                }

                if ($score > 0) {
                    $candidates[] = [
                        'id' => (int) $e['id'],
                        'score' => (int) $score,
                    ];
                }
            }
        }

        if (empty($candidates)) {
            return null;
        }

        usort($candidates, fn ($a, $b) => $b['score'] <=> $a['score']);

        $best = $candidates[0];
        $second = $candidates[1] ?? null;

        if ($best['score'] < 260) {
            return null;
        }

        if ($second && $best['score'] < 1000) {
            if (($best['score'] - $second['score']) < 40) {
                return ['ambiguous' => true];
            }
        }

        return $best['id'];
    }

    private function matchTeamIds(string $clubName, array $teamsNorm): array
    {
        $club = $this->norm($clubName);
        if ($club === '') return [];

        $aliases = [
            'manchester united' => ['man utd'],
            'manchester city' => ['man city'],
            'tottenham hotspur' => ['spurs'],
            'wolverhampton wanderers' => ['wolves'],
            'nottingham forest' => ['nottm forest', 'nottingham forest'],
        ];

        foreach ($aliases as $needle => $names) {
            if (!str_contains($club, $needle)) continue;

            $hits = [];
            foreach ($teamsNorm as $t) {
                $tn = $t['norm_name'] ?? '';
                $ts = $t['norm_short'] ?? '';
                if ($tn === '' && $ts === '') continue;

                foreach ($names as $n) {
                    if (($tn !== '' && (str_contains($tn, $n) || str_contains($n, $tn))) || ($ts !== '' && (str_contains($ts, $n) || str_contains($n, $ts)))) {
                        $hits[] = (int) $t['id'];
                        break;
                    }
                }
            }

            $hits = array_values(array_unique($hits));
            if (!empty($hits)) return $hits;
        }

        $hits = [];

        foreach ($teamsNorm as $t) {
            $tn = $t['norm_name'];
            $ts = $t['norm_short'];

            $ok = false;

            if ($tn !== '' && (str_contains($club, $tn) || str_contains($tn, $club))) $ok = true;
            if (!$ok && $ts !== '' && (str_contains($club, $ts) || str_contains($ts, $club))) $ok = true;

            if ($ok) {
                $hits[] = (int) $t['id'];
            }
        }

        return array_values(array_unique($hits));
    }

    private function tokens(string $s): array
    {
        $s = $this->norm($s);
        if ($s === '') return [];

        $parts = preg_split('/\s+/', $s);
        $stop = ['de','da','do','dos','van','von','del','della','di','la','le','el','al','bin','ibn','jr','sr'];

        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '') continue;
            if (in_array($p, $stop, true)) continue;
            $out[] = $p;
        }

        return $out;
    }

    private function overlap(array $a, array $b): float
    {
        if (empty($a) || empty($b)) return 0.0;

        $setB = array_fill_keys($b, true);
        $hit = 0;

        foreach ($a as $t) {
            if (isset($setB[$t])) $hit++;
        }

        return $hit / max(count($a), 1);
    }

    private function guessFplTypeFromPosShort(?string $posShort): ?int
    {
        if (!$posShort) return null;
        $p = strtoupper(trim($posShort));

        if ($p === 'GK') return 1;

        $def = ['CB','LB','RB','LWB','RWB','WB'];
        $fwd = ['ST','CF'];
        if (in_array($p, $def, true)) return 2;
        if (in_array($p, $fwd, true)) return 4;

        return 3;
    }

    private function norm(string $s): string
    {
        $s = mb_strtolower($s);

        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($t !== false && $t !== null) {
            $s = $t;
        }

        $s = str_replace(['.', ',', '\'', '"', '’', '`'], '', $s);
        $s = str_replace(['-', '_'], ' ', $s);
        $s = str_replace(['fc', 'afc', 'cf'], '', $s);
        $s = preg_replace('/[^a-z0-9\s]/u', ' ', $s);
        $s = preg_replace('/\s+/', ' ', trim($s));

        return $s;
    }
}

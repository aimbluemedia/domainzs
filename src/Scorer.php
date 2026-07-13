<?php
declare(strict_types=1);

namespace Domainzs;

/**
 * Heuristic domain-name scorer: rates an SLD 0–99 for brandability/resale
 * appeal and explains why. Deterministic, instant, no network calls — every
 * drop gets a score, and the top of the board can optionally get an AI
 * second opinion on top (AiRater).
 */
final class Scorer
{
    /** Common English words worth finding inside a name (4–6 letters). */
    private const WORDS = [
        'able','apex','aqua','atom','aura','auto','back','band','bank','base','beam','bear','best','bird','blue',
        'bold','bolt','bond','book','boss','brand','brew','buzz','cake','call','calm','camp','card','care','cash',
        'cast','chat','chef','city','claim','clan','clip','cloud','club','coach','coast','code','coin','cool',
        'core','corp','craft','crew','crop','crown','cube','cure','cyber','dash','data','deal','deck','deep',
        'desk','dial','digit','dock','doll','door','dose','draft','dream','drive','drop','earn','east','easy',
        'echo','edge','elite','epic','face','farm','fast','feed','field','film','find','fire','firm','fish',
        'flag','flash','fleet','flex','flip','flow','folk','food','forge','form','fort','forum','free','fresh',
        'frog','fuel','fund','game','gate','gear','gift','giga','glow','goal','gold','good','grand','green',
        'grid','group','grow','guard','guide','hack','hand','happy','haven','head','heart','herb','hero','hill',
        'hive','home','host','house','hyper','icon','idea','India','iron','jump','just','keen','king','kite',
        'lab','lake','land','lane','laser','launch','lead','leaf','lean','life','lift','light','like','line',
        'link','lion','list','live','loan','local','lock','loft','logic','look','loop','luck','lunar','magic',
        'mail','main','maker','manor','maple','mark','mart','mate','media','mega','merge','meta','mind','mine',
        'mint','mode','moon','more','move','music','nest','next','nice','ninja','node','north','note','nova',
        'ocean','offer','omega','open','orbit','order','pace','pack','page','paint','panda','park','part',
        'path','peak','pearl','phase','pilot','pixel','plan','play','plus','point','pool','port','power',
        'press','prime','print','prize','probe','proof','pulse','pure','push','quest','quick','radar','rail',
        'rain','rally','range','rank','rapid','reach','ready','realm','rich','ride','ring','rise','river',
        'road','rock','root','rose','route','royal','rush','safe','sage','sail','sale','salt','scale','scan',
        'scope','scout','seal','seed','shark','sharp','shift','shine','ship','shop','shore','sight','sign',
        'silk','site','skill','slate','smart','snap','solar','solid','sonic','south','space','spark','speed',
        'spice','spin','sport','spot','stack','star','start','state','stay','steel','stone','store','storm',
        'story','strike','strong','studio','style','surge','swift','table','tale','talk','team','tech','tide',
        'tiger','time','tool','top','torch','totem','touch','tower','track','trade','trail','train','tree',
        'trend','tribe','true','trust','turbo','twist','ultra','union','unit','urban','value','vault','venture',
        'verse','vibe','view','vine','vista','vital','vivid','voice','volt','wave','wealth','west','whale',
        'wheel','wide','wild','wind','wing','wire','wise','wolf','wood','word','work','world','yard','year',
        'zone','zoom',
    ];

    /** Suffixes that read as product/company names. */
    private const HOT_SUFFIXES = ['hub', 'lab', 'labs', 'ify', 'app', 'box', 'pro', 'kit', 'base', 'zone', 'spot', 'wire', 'ware', 'gram', 'stack', 'craft', 'works', 'point', 'space', 'cloud'];

    /** Prefixes that read as brand starters. */
    private const HOT_PREFIXES = ['get', 'try', 'go', 'my', 'the', 'top', 'pro', 'max', 'neo', 'uber'];

    /**
     * Score an SLD (the name without the TLD).
     *
     * @return array{score:int, notes:string[]}
     */
    public static function score(string $sld): array
    {
        $s     = strtolower($sld);
        $score = 50;
        $notes = [];

        // --- Hard negatives -------------------------------------------------
        if (str_contains($s, '-')) {
            $score -= 22;
            $notes[] = 'contains a hyphen';
        }
        $digits = preg_match_all('/[0-9]/', $s);
        if ($digits > 0) {
            $score -= 8 + 6 * $digits;
            $notes[] = 'contains digits';
        }

        $letters = preg_replace('/[^a-z]/', '', $s) ?? '';

        // --- Pronounceability ------------------------------------------------
        $vowels = preg_match_all('/[aeiouy]/', $letters);
        $ratio  = strlen($letters) > 0 ? $vowels / strlen($letters) : 0;
        if ($ratio >= 0.28 && $ratio <= 0.55) {
            $score += 12;
            $notes[] = 'good vowel balance — easy to say';
        } elseif ($vowels === 0) {
            $score -= 22;
            $notes[] = 'no vowels — unpronounceable';
        } else {
            $score -= 6;
            $notes[] = 'awkward vowel balance';
        }
        if (preg_match('/[bcdfghjklmnpqrstvwxz]{4,}/', $letters)) {
            $score -= 12;
            $notes[] = 'heavy consonant cluster';
        }
        if (preg_match('/(.)\1\1/', $letters)) {
            $score -= 10;
            $notes[] = 'triple repeated letter';
        }

        // --- Dictionary-word detection ---------------------------------------
        $found = self::findWords($letters);
        if (count($found) >= 2) {
            $score += 26;
            $notes[] = 'two real words: ' . implode(' + ', array_slice($found, 0, 2));
        } elseif (count($found) === 1) {
            $score += 13;
            $notes[] = "contains the word '{$found[0]}'";
        }

        // --- Brandable patterns ----------------------------------------------
        foreach (self::HOT_SUFFIXES as $suffix) {
            if (str_ends_with($s, $suffix)) {
                $score += 9;
                $notes[] = "ends in '-{$suffix}' (startup-style)";
                break;
            }
        }
        foreach (self::HOT_PREFIXES as $prefix) {
            if (str_starts_with($s, $prefix) && strlen($s) > strlen($prefix) + 2) {
                $score += 5;
                $notes[] = "starts with '{$prefix}-' (call-to-action)";
                break;
            }
        }
        if (preg_match('/(oo|ee)/', $letters)) {
            $score += 3;
            $notes[] = 'friendly double vowel';
        }

        // --- Letter quality ----------------------------------------------------
        $rare = preg_match_all('/[qxzj]/', $letters);
        if ($rare >= 2) {
            $score -= 5 * ($rare - 1);
            $notes[] = 'several rare letters';
        }

        $score = max(3, min(99, $score));
        return ['score' => $score, 'notes' => $notes];
    }

    /**
     * Greedy left-to-right split into known words (longest match first).
     * "cloudbase" → [cloud, base]; partial coverage returns what was found.
     *
     * @return string[]
     */
    private static function findWords(string $letters): array
    {
        static $bySize = null;
        if ($bySize === null) {
            $bySize = [];
            foreach (self::WORDS as $word) {
                $word = strtolower($word);
                $bySize[strlen($word)][$word] = true;
            }
            krsort($bySize);
        }

        $found = [];
        $pos   = 0;
        $len   = strlen($letters);
        while ($pos < $len && count($found) < 3) {
            $matched = false;
            foreach ($bySize as $size => $words) {
                if ($pos + $size <= $len && isset($words[substr($letters, $pos, $size)])) {
                    $found[] = substr($letters, $pos, $size);
                    $pos    += $size;
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                $pos++;
            }
        }
        return $found;
    }
}

<?php
declare(strict_types=1);

namespace Domainzs;

/**
 * Compound-word finder. Picks out drops whose SLD is two real English words
 * stuck together — the "brandable two-word .com" pattern like voltget.com or
 * jeeppup.com. Both halves must be known dictionary words.
 *
 * Uses a curated word list (short, brandable words carry these names) rather
 * than a full dictionary, so obscure junk splits don't slip through. The list
 * lives in words() and is intentionally biased toward common, "namey" words.
 */
final class Compounds
{
    /** @var array<string,true>|null lazily-built lookup set */
    private static ?array $set = null;

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Return drops (len 5–9) whose SLD splits cleanly into two known words.
     * Each row gains: word_a, word_b, split ("volt+get"), balance (min half len).
     *
     * @param array{len?:int,q?:string,date?:string,min?:int,sort?:string} $f
     * @return array<int,array<string,mixed>>
     */
    public function find(array $f = []): array
    {
        $len  = (int)($f['len'] ?? 0);           // 0 = 5..9, else exact
        $q    = trim((string)($f['q'] ?? ''));
        $date = (string)($f['date'] ?? '');
        $min  = (int)($f['min'] ?? 0);

        $where  = 'len BETWEEN 5 AND 9';
        $params = [];
        if ($len >= 5 && $len <= 9) { $where .= ' AND len = ?';   $params[] = $len; }
        if ($q !== '')              { $where .= ' AND sld LIKE ?'; $params[] = '%' . $q . '%'; }
        if ($date !== '')           { $where .= ' AND dropped_date = ?'; $params[] = $date; }
        if ($min > 0)               { $where .= ' AND score >= ?'; $params[] = $min; }

        // Pull a generous window; splitting is cheap and we filter in PHP.
        $stmt = $this->pdo->prepare(
            "SELECT * FROM drops WHERE {$where} ORDER BY len ASC, score DESC LIMIT 2000"
        );
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $split = $this->split((string)$row['sld']);
            if ($split === null) {
                continue;
            }
            [$a, $b] = $split;
            $row['word_a'] = $a;
            $row['word_b'] = $b;
            $row['split']  = $a . '+' . $b;
            $row['balance'] = min(strlen($a), strlen($b));
            $out[] = $row;
        }

        // Sort: most balanced pairs first (both halves substantial), then score.
        $sort = (string)($f['sort'] ?? 'balance');
        usort($out, function ($x, $y) use ($sort) {
            if ($sort === 'score') {
                return (int)$y['score'] <=> (int)$x['score']
                    ?: (int)$y['balance'] <=> (int)$x['balance'];
            }
            if ($sort === 'az') {
                return strcmp((string)$x['sld'], (string)$y['sld']);
            }
            if ($sort === 'len') {
                return (int)$x['len'] <=> (int)$y['len']
                    ?: (int)$y['balance'] <=> (int)$x['balance'];
            }
            // balance (default): both-real-words names bubble up
            return (int)$y['balance'] <=> (int)$x['balance']
                ?: (int)$y['score'] <=> (int)$x['score'];
        });

        return $out;
    }

    /**
     * Split an SLD into two known words. Returns [left, right] or null.
     * When several splits work, prefer the most balanced (both halves long),
     * which reads as the most natural two-word name.
     *
     * @return array{0:string,1:string}|null
     */
    public function split(string $sld): ?array
    {
        $s = strtolower(preg_replace('/[^a-z]/i', '', $sld));
        $n = strlen($s);
        if ($n < 4) {
            return null;
        }
        $dict = self::words();
        $best = null;
        $bestScore = -1;
        for ($i = 2; $i <= $n - 2; $i++) {
            $a = substr($s, 0, $i);
            $b = substr($s, $i);
            if (isset($dict[$a]) && isset($dict[$b])) {
                // Favour balanced splits (both halves ≥3), penalise 2-letter halves.
                $score = min(strlen($a), strlen($b)) * 2 + strlen($a) + strlen($b);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = [$a, $b];
                }
            }
        }
        return $best;
    }

    /** @return array<string,true> the word lookup set (built once). */
    private static function words(): array
    {
        if (self::$set !== null) {
            return self::$set;
        }
        $words = <<<'WORDS'
ace ache acid acorn acre act add age ago aid aim air ale all ally amber amp ant
ape apex app arc arch area arm army art ash ask atom aura auto awe axe axis baby
back bad bag bait bake ball band bank bar bare bark barn base bash bass bat batch
bath bay beach beam bean bear beat bee beef beer bell belt bend berry best bet big
bike bill bin bind bird bit bite black blade blast blaze blend bliss block blog
bloom blue blur board boat body bold bolt bomb bond bone bonus book boom boost boot
border boss bot bounce bow bowl box boy brain brand brave bread break brew brick
bridge bright bring brisk broad bronze brook brow brown brush buck bud buddy budget
buffer bug build bulb bulk bull bump bun bunch bundle bunny burn burst bus bush
buzz cab cable cache cage cake call calm camp can candy cane cap cape car card care
cargo cart case cash cast cat catch cave cedar cell cent chain chair chalk champ
chance chaos charge charm chart chase chat cheap check cheer chef cherry chess chest
chick chief chill chip choice chop chord chrome chunk city civic claim clam clan clap
class claw clay clean clear clerk click cliff climb clip clock clone close cloth cloud
club clue coach coal coast coat cobra code coil coin cold color colt comb come comet
comic cook cool cop copper copy coral cord core corn cost cot couch cough count court
cove cover cow cozy crab crack craft cram crane crash crate crave crawl crazy cream
crest crew crib cricket crisp crop cross crow crowd crown crux cube cue cup curl
current curve cut cute cyber dab daily dairy daisy dam dance dandy dark dart dash data
date dawn day dazzle deal dean dear debt deck deed deep deer den dense depot desk
diamond dice diesel dig dime diner dip dish disk ditch dive divine dock dodge dog doll
dollar dolphin dome done door dot dove down draft drag dragon drake drama draw dream
dress drift drill drink drip drive drone drop drum dry duck due duke dune dusk dust
duty dwarf eager eagle ear earl early earn earth ease east easy eat echo edge eel egg
elbow elder elf elite elk elm ember emerald empire end energy engine enjoy envy epic
equal era erase error escape essence estate eve even event ever evil exact exit expert
extra eye fable face fact fade fair fairy faith fall fame fan fancy fang far farm fast
fate fault fawn fear feast feat feather fee feed feel fell felt fence fern ferry fever
few fiber field fierce fig fight file fill film fin final finch find fine finger fir
fire firm first fish fist fit five fix flag flair flame flap flare flash flask flat
flavor flaw fleet flesh flex flick flint flip float flock flood floor flora flour flow
flower fluid flux fly foam focus fog foil fold folk font food fool foot force ford
forest forge fork form fort forum fossil found fox frame free fresh friar friend frog
front frost fruit fry fuel full fun fund fur fury fuse fuzz gain gala galaxy gale gall
game gang gap garden gas gate gather gaze gear gecko gem gene genius gentle germ get
ghost giant gift gig gild gin ginger girl give glad glass gleam glide glint globe gloom
glory gloss glove glow glue goal goat god gold golf gong good goose gorge gown grab
grace grade grail grain grand grant grape graph grasp grass grave gray graze great
greed green greet grid grill grim grin grind grip grit groom groove ground group
grove grow growl guard guess guest guide guild gulf gull gum gust guy gym hail hair
half hall halo ham hand hang happy harbor hard hare harm harp harsh harvest hash
haste hat hatch haul haven hawk hay haze head heal heap heart heat heavy hedge heel
height helm help hen herb herd hero hill hint hip hire hit hive hobby hog hold hole
holly holy home honey honor hood hoof hook hop hope horn horse host hot hound hour
house hub hue hug hull human hum hunt hurl hut ice icon idea ideal idle igloo image
inch index ink inn iris iron island issue item ivory ivy jab jack jade jaguar jam jar
jaw jay jazz jean jeep jelly jet jewel job jog join joint joke jolly jolt joy judge
juice jump june jungle junior junk jury just kale kart keen keep kelp ken kettle key
kick kid kilo kin kind king kiss kit kite kitten knee knight knit knob knot know koala
lab lace lad lake lamb lamp lance land lane lap lark lash last latch late laugh launch
lava law lawn lay lead leaf league leap learn lease leash least leaf leaf ledge leek
left leg legend lemon lend lens lever levy lick lid life lift light lily limb lime
limit line link lion lip liquid list lit live lizard load loaf loan lobby local lock
lodge loft log logic lone long look loom loop loose lord lore lot lotus loud love low
loyal luck lucky lull lumber lump lunar lunch lung lure lush lux lyric mace mad magic
magma maid mail main major make mall malt mammoth man manor mango maple map marble
march mare mark marsh mart mask mast match mate math matrix matter maze meadow meal
mean meat medal media medic meet mega melody melon melt member memo mend menu mercy
merge merit merry mesh mess metal meteor meter method mid might mild mile milk mill
mind mine mini mink mint minute mirror mist mix moat mob mode model modem mojo mold
mole mom moment monk monkey mood moon moose moral morph moss most moth motion motor
mound mount mouse mouth move movie much mud mug mule muse music must mute nacho nail
name nano nap narrow nation native nature navy near neat neck need needle neon nerve
nest net nettle never new news next nice niche night nimble nine noble node noise
nomad noon norm north nose note nova novel now nudge null nurse nut oak oar oasis oat
oath ocean odd ode oil old olive omega once one onion only onyx open opera opal orbit
orange orbit orca orchard order ore organ oscar otter ounce oust outer oval oven over
owl own ox oxide pace pack pact pad page pail pain paint pair pal palm pan panda panel
pant paper parcel park parrot part party pass past pasta patch path patio paw pea peace
peach peak pear pearl pebble peck pedal peel pen penny people pepper perch perk pest
pet petal phase phone photo piano pick pie piece pier pig pike pile pill pilot pin pine
pink pint pipe pirate pit pitch pixel pizza place plain plan plane plant plasma plate
play plaza plea pledge plot plow pluck plug plum plume plus pocket pod poem poet point
poise poke polar pole police polish poll pond pony pool poor pop porch port pose posh
post pot potato pouch pound pour power praise prank prawn pray press prime print prism
prize probe prof profit prom proof props proud prove prowl prune public puck puddle
puff pull pulp pulse puma pump pun punch pup pupil pure purple purse push put puzzle
quail quake quart queen quest queue quick quiet quill quilt quirk quiz quota rabbit
race rack radar radio raft rage raid rail rain raise rake rally ram ramp ranch range
rank rapid rare rash rat rate raven raw ray reach react read realm reap rear rebel
recap red reed reef reel reign rein relay relic relish remedy rent reset resin rest
retro rev revel reward rhino rib rice rich ride ridge rift rig right rim ring rinse
riot rip ripe ripple rise risk rival river road roam roar roast robe robin robot rock
rocket rod rogue role roll roof rook room roost root rope rose rosy rot rough round
route rover row royal rub ruby rug rule rum run rune rung rural rush rust sable sack
safe saffron sage sail saint sake salad sale salt salmon salon salt salute same sample
sand sane sap sash satin sauce save saw say scale scan scar scare scarf scene scent
scheme scholar school scoop scope score scorn scout scrap scrub sea seal seam search
season seat sect sedan seed seek seem seer sell send sense sent sentry serene serve set
seven shack shade shadow shaft shake shale shall shame shape shard share shark sharp
shatter shave shed sheen sheep sheer sheet shelf shell shield shift shine ship shire
shirt shock shoe shone shoot shop shore short shot shout shove show shower shred shrewd
shrine shrub shrug shy sick side siege sift sigh sight sign silk silver simple sin sip
sir siren sit site six size skate sketch ski skill skin skip skirt skull sky slab slam
slate sled sleek sleep sleet slice slick slide slim slime sling slip slit slope slot
slow slug small smart smash smile smith smoke smooth snack snail snake snap snare sneak
snip snoop snow snug soak soap soar sock soda sofa soft soil solar sold sole solid solo
solve some song sonic soon soot soul sound soup sour source south sow soy space spade
spar spark spawn speak spear speck speed spell spend sphere spice spider spike spin
spine spire spirit spit splash split spoke sponge spool spoon sport spot spout spray
spread spring sprint sprout spruce spur spy square squad squid stable stack staff stag
stage stain stair stake stale stalk stall stamp stand star stark start stash state
stay steak steal steam steel steep steer stem step stern stew stick stiff still sting
stir stock stone stool stop store stork storm story stout stove stow strap straw stray
stream street stress strike string strip strive stroke strong strut stub stud study
stuff stump stun sturdy style sub such sugar suit sum summer summit sun sundae super
sure surf swan swap swarm sway swear sweat sweep sweet swell swift swim swing swirl
switch sword syrup table tack taco tag tail take talc tale talent talk tall tame tan
tank tap tape target tart task taste tattoo taut tax taxi tea teach teak team tear
tech teddy tee teen tempo tempt ten tender tennis tent term test text than thank thaw
theme there thick thief thin thing think third thorn thread three thrill throne throw
thumb thunder tick ticket tide tidy tie tiger tight tile till tilt timber time tin tint
tiny tip tire toad toast today toe tofu token tomato tomb tone tongue tool tooth top
torch total tote touch tough tour tower town toy trace track trade trail train trait
tram trap trash travel tray tread treat tree trek trend trial tribe trick trim trio
trip troll troop trophy trot trout truck true trump trunk trust truth try tub tube tuck
tulip tuna tune tunnel turbo turf turn turtle tusk tutor twig twin twist two tycoon
type udder ugly ultra umber under unify unit unity urban urge use usher vacant vague
vale valet valid valley value valve van vanilla vapor vase vast vault veer vein velvet
vendor vent venue verb verge verse very vessel vest vet vibe vice video view vigor
villa vine vinyl viola violet viper viral virtue visa vision visit vital vivid vocal
vogue voice void volt vote vow voyage wad wade wag wage wagon wake walk wall walnut
walrus wand want war ward ware warm warp wart wary wash wasp waste watch water watt wave
wax way weak wealth wear weave web wedge weed week weep weigh weird well west wet whale
wheat wheel whip whirl whisk white whole wick wide widget wild will win wind wine wing
wink winter wipe wire wise wish wit witch wolf woman wonder wood wool word work world
worm worry worth wound woven wrap wren wrist write yacht yak yam yard yarn yawn year
yeast yell yellow yes yeti yield yoga yogurt yolk you young yowl zeal zebra zen zero
zest zigzag zinc zip zone zoo zoom
WORDS;
        $set = [];
        foreach (preg_split('/\s+/', trim($words)) as $w) {
            if ($w !== '') {
                $set[$w] = true;
            }
        }
        return self::$set = $set;
    }
}

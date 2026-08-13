<?php

declare(strict_types=1);

/**
 * DonorConnect ENBTS — Full Donor Pool + Hospital Seed
 * =====================================================
 * Seeds:
 *   - 13 hospitals across all four Eswatini regions
 *   - 200 realistic donor accounts with varied blood types,
 *     verification status, eligibility and donation history
 *
 * Safe to re-run: institutions and users are upserted by name / national-ID hash.
 *
 * Run:
 *   php api/scripts/seed_donors_and_hospitals.php
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Core\App;
use App\Core\Database;
use App\Core\Identity;

$db     = Database::connection();
$crypto = App::crypto();

// ──────────────────────────────────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────────────────────────────────

function upsertInstitution(
    PDO    $db,
    string $name,
    string $type,
    string $region,
    string $town,
    string $phone,
    string $email,
    string $address
): int {
    $sel = $db->prepare('SELECT id FROM institutions WHERE name = :name LIMIT 1');
    $sel->execute(['name' => $name]);
    $id  = $sel->fetchColumn();
    if ($id) {
        $db->prepare(
            'UPDATE institutions SET institution_type=:type, region=:region, town=:town,
             phone=:phone, email=:email, address=:address, is_active=1 WHERE id=:id'
        )->execute(['type'=>$type,'region'=>$region,'town'=>$town,'phone'=>$phone,'email'=>$email,'address'=>$address,'id'=>$id]);
        return (int) $id;
    }
    $db->prepare(
        'INSERT INTO institutions (name,institution_type,phone,email,region,town,address,is_active)
         VALUES (:name,:type,:phone,:email,:region,:town,:address,1)'
    )->execute(['name'=>$name,'type'=>$type,'phone'=>$phone,'email'=>$email,'region'=>$region,'town'=>$town,'address'=>$address]);
    return (int) $db->lastInsertId();
}

function upsertDonor(PDO $db, $crypto, array $d, int $staffId): void
{
    $nationalId = Identity::nationalId($d['national_id']);
    $phone      = Identity::phone($d['phone']);
    $hash       = $crypto->searchHash($nationalId);

    // Skip if national ID or phone already registered
    $exists = $db->prepare('SELECT id FROM users WHERE national_id_hash=:h OR phone=:p LIMIT 1');
    $exists->execute(['h' => $hash, 'p' => $phone]);
    if ($exists->fetchColumn()) {
        return; // Already seeded — idempotent
    }

    $birthDate = Identity::birthDateFromNationalId($nationalId);
    if (!$birthDate) {
        echo "  [SKIP] Invalid national ID: {$d['national_id']}\n";
        return;
    }
    if (!Identity::isOldEnoughToRegister($nationalId)) {
        echo "  [SKIP] Too young: {$d['full_name']}\n";
        return;
    }

    $db->beginTransaction();
    try {
        // Insert user
        $db->prepare(
            "INSERT INTO users
             (institution_id, full_name, national_id_encrypted, national_id_hash, national_id_last_four,
              email, phone, password_hash, password_status, role, account_status, created_at, updated_at)
             VALUES
             (:iid, :name, :enc, :hash, :l4, :email, :phone, :pw, :pws, 'donor', 'active', :created_at, :updated_at)"
        )->execute([
            'iid'   => $d['institution_id'],
            'name'  => $d['full_name'],
            'enc'   => $crypto->encrypt($nationalId),
            'hash'  => $hash,
            'l4'    => substr($nationalId, -4),
            'email' => $d['email'] ?? null,
            'phone' => $phone,
            'pw'    => null,
            'pws'   => 'unset',
            'created_at' => $d['created_at'],
            'updated_at' => $d['created_at'],
        ]);
        $userId    = (int) $db->lastInsertId();
        $donorCode = sprintf('DC-%s-%06d', date('Y'), $userId);

        // Insert donor profile
        $db->prepare(
            "INSERT INTO donor_profiles
             (user_id, donor_code, blood_type, date_of_birth, gender, region, town,
              availability_status, verification_status, eligibility_status,
              preferred_contact_method, recruitment_source, recruitment_institution_id,
              emergency_contact_name, emergency_contact_phone, consent_to_notifications,
              profile_completion_score, last_donation_date, next_eligible_date, total_donations, created_at, updated_at)
             VALUES
             (:uid, :code, :bt, :dob, :gender, :region, :town,
              :avail, :verif, :elig,
              'sms', :source, :riid,
              :ecname, :ecphone, 1,
              :score, :ldd, :ned, :tdons, :created_at, :updated_at)"
        )->execute([
            'uid'    => $userId,
            'code'   => $donorCode,
            'bt'     => $d['blood_type'],
            'dob'    => $birthDate->format('Y-m-d'),
            'gender' => $d['gender'],
            'region' => $d['region'],
            'town'   => $d['town'],
            'avail'  => $d['availability_status'],
            'verif'  => $d['verification_status'],
            'elig'   => $d['eligibility_status'],
            'source' => $d['recruitment_source'],
            'riid'   => $d['institution_id'],
            'ecname' => $d['emergency_contact_name'],
            'ecphone'=> Identity::phone($d['emergency_contact_phone']),
            'score'  => $d['verification_status'] === 'verified' ? 100 : 70,
            'ldd'    => $d['last_donation_date'] ?? null,
            'ned'    => $d['next_eligible_date'] ?? null,
            'tdons'  => $d['total_donations'],
            'created_at' => $d['created_at'],
            'updated_at' => $d['created_at'],
        ]);
        $donorId = (int) $db->lastInsertId();

        // Activity log: registered
        $db->prepare(
            "INSERT INTO donor_activity_logs (donor_id, activity_type, description, metadata, created_at)
             VALUES (:did, 'registered', :desc, :meta, :created_at)"
        )->execute([
            'did'  => $donorId,
            'desc' => 'Donor registered via ENBTS seed script.',
            'meta' => json_encode(['registered_by' => $staffId, 'source' => $d['recruitment_source']], JSON_THROW_ON_ERROR),
            'created_at' => $d['created_at'],
        ]);

        // Seed donation records for donors with a donation history
        if ($d['total_donations'] > 0 && $d['last_donation_date']) {
            $donTypes = ['whole_blood', 'whole_blood', 'whole_blood', 'plasma', 'platelets'];
            for ($n = 0; $n < $d['total_donations']; $n++) {
                // Each donation ~4 months apart going backwards from last_donation_date
                $offset = $n * 4;
                $donDate  = date('Y-m-d', strtotime("-{$offset} months", strtotime($d['last_donation_date'])));
                $nedDate  = date('Y-m-d', strtotime("+3 months", strtotime($donDate)));
                $donType  = $donTypes[$n % count($donTypes)];
                $db->prepare(
                    "INSERT INTO donation_records
                     (donor_id, institution_id, recorded_by, donation_date, donation_type,
                      units, region, town, next_eligible_date, screening_status, created_at)
                     VALUES (:did, :iid, :rby, :dd, :dt, 1, :reg, :twn, :ned, 'passed', :created_at)"
                )->execute([
                    'did' => $donorId,
                    'iid' => $d['institution_id'],
                    'rby' => $staffId,
                    'dd'  => $donDate,
                    'dt'  => $donType,
                    'reg' => $d['region'],
                    'twn' => $d['town'],
                    'ned' => $nedDate,
                    'created_at' => $donDate . ' 10:00:00',
                ]);
                $db->prepare(
                    "INSERT INTO donor_activity_logs (donor_id, activity_type, description, metadata, created_at)
                     VALUES (:did, 'donation_recorded', :desc, :meta, :created_at)"
                )->execute([
                    'did'  => $donorId,
                    'desc' => "Donation recorded on {$donDate} (seed).",
                    'meta' => json_encode(['donation_type' => $donType, 'units' => 1], JSON_THROW_ON_ERROR),
                    'created_at' => $donDate . ' 10:00:00',
                ]);
            }
        }
        // Seed deferrals if applicable
        if ($d['eligibility_status'] === 'temporarily_deferred') {
            $db->prepare(
                "INSERT INTO donor_deferrals (donor_id, recorded_by, deferral_type, reason, starts_on, ends_on, status, notes, created_at)
                 VALUES (:did, :rby, 'temporary', 'Seeded temporary deferral', :starts, :ends, 'active', 'Automatically created by seed script.', :created_at)"
            )->execute([
                'did' => $donorId,
                'rby' => $staffId,
                'starts' => $d['last_donation_date'],
                'ends' => $d['next_eligible_date'],
                'created_at' => $d['last_donation_date'] . ' 11:00:00',
            ]);
        } elseif ($d['eligibility_status'] === 'permanently_deferred') {
            $reasons = [
                'Hepatitis B positive history confirmation.',
                'HIV positive confirmation during routine screen.',
                'Chronic cardiovascular medical condition.',
                'Severe recurring anemia or clinical contraindication.',
                'Severe chronic health disorder.'
            ];
            $reason = $reasons[$donorId % count($reasons)];
            $db->prepare(
                "INSERT INTO donor_deferrals (donor_id, recorded_by, deferral_type, reason, starts_on, ends_on, status, notes, created_at)
                 VALUES (:did, :rby, 'permanent', :reason, :starts, NULL, 'active', 'Permanently deferred for safety.', :created_at)"
            )->execute([
                'did' => $donorId,
                'rby' => $staffId,
                'reason' => $reason,
                'starts' => $d['last_donation_date'],
                'created_at' => $d['last_donation_date'] . ' 11:00:00',
            ]);
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        echo "  [ERROR] {$d['full_name']}: {$e->getMessage()}\n";
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// Seed: Look up the Mbabane Blood Bank admin (needed as "recorded_by" for
//       donation records and as staff reference — use the first admin found).
// ──────────────────────────────────────────────────────────────────────────────

$adminStmt = $db->query("SELECT id FROM users WHERE role='admin' LIMIT 1");
$adminRow  = $adminStmt->fetch();
if (!$adminRow) {
    echo "ERROR: No admin user found. Run seed_enbts_demo.php first.\n";
    exit(1);
}
$staffId = (int) $adminRow['id'];

// ──────────────────────────────────────────────────────────────────────────────
// HOSPITALS (13 across all 4 regions)
// ──────────────────────────────────────────────────────────────────────────────

echo "Seeding hospitals...\n";

$hospitals = [
    // ── Hhohho ────────────────────────────────────────────────────────────────
    ['name' => 'Mbabane Government Hospital',        'region' => 'Hhohho',     'town' => 'Mbabane',     'phone' => '+26824042111', 'email' => 'blooddesk@mgh.gov.sz',       'address' => 'Mbabane Government Hospital, Mbabane'],
    ['name' => "Pigg's Peak Government Hospital",    'region' => 'Hhohho',     'town' => "Pigg's Peak", 'phone' => '+26824371000', 'email' => 'blooddesk@piggspeak.gov.sz',  'address' => "Pigg's Peak Hospital, Hhohho"],
    ['name' => 'Lobamba Clinic & Referral Centre',   'region' => 'Hhohho',     'town' => 'Lobamba',     'phone' => '+26824161050', 'email' => 'referrals@lobamba.gov.sz',    'address' => 'Lobamba Health Centre, Lobamba'],
    // ── Manzini ───────────────────────────────────────────────────────────────
    ['name' => 'Raleigh Fitkin Memorial Hospital',   'region' => 'Manzini',    'town' => 'Manzini',     'phone' => '+26825052211', 'email' => 'blooddesk@rfm.org.sz',        'address' => 'RFM Hospital, Manzini'],
    ['name' => 'Nazarene Hospital Manzini',          'region' => 'Manzini',    'town' => 'Manzini',     'phone' => '+26825052500', 'email' => 'blooddesk@nazarene.org.sz',   'address' => 'Nazarene Hospital, Manzini'],
    ['name' => 'Mankayane Government Hospital',      'region' => 'Manzini',    'town' => 'Mankayane',   'phone' => '+26825351100', 'email' => 'blooddesk@mankayane.gov.sz',  'address' => 'Mankayane Government Hospital, Manzini Region'],
    ['name' => 'Matsapha Health Centre',             'region' => 'Manzini',    'town' => 'Matsapha',    'phone' => '+26825183000', 'email' => 'blooddesk@matsapha.gov.sz',   'address' => 'Matsapha Health Centre, Manzini'],
    // ── Lubombo ───────────────────────────────────────────────────────────────
    ['name' => 'Lubombo Referral Hospital',          'region' => 'Lubombo',    'town' => 'Siteki',      'phone' => '+26823343000', 'email' => 'blooddesk@lubomboreferral.sz','address' => 'Lubombo Referral Hospital, Siteki'],
    ['name' => 'Good Shepherd Hospital Siteki',      'region' => 'Lubombo',    'town' => 'Siteki',      'phone' => '+26823343500', 'email' => 'blooddesk@gshospital.org.sz', 'address' => 'Good Shepherd Hospital, Siteki, Lubombo'],
    ['name' => 'Big Bend Health Centre',             'region' => 'Lubombo',    'town' => 'Big Bend',    'phone' => '+26823633100', 'email' => 'blooddesk@bigbend.gov.sz',    'address' => 'Big Bend Health Centre, Lubombo Region'],
    ['name' => 'Mhlume Hospital',                    'region' => 'Lubombo',    'town' => 'Mhlume',      'phone' => '+26823413200', 'email' => 'blooddesk@mhlume.gov.sz',     'address' => 'Mhlume Hospital, Lubombo Region'],
    // ── Shiselweni ────────────────────────────────────────────────────────────
    ['name' => 'Hlatikhulu Government Hospital',     'region' => 'Shiselweni', 'town' => 'Hlathikhulu', 'phone' => '+26822207100', 'email' => 'blooddesk@hlatikhulu.gov.sz', 'address' => 'Hlatikhulu Government Hospital, Shiselweni'],
    ['name' => 'Nhlangano Health Centre',            'region' => 'Shiselweni', 'town' => 'Nhlangano',   'phone' => '+26822207500', 'email' => 'blooddesk@nhlangano.gov.sz',  'address' => 'Nhlangano Health Centre, Shiselweni Region'],
];

$hospitalIds = [];
foreach ($hospitals as $h) {
    $hid = upsertInstitution($db, $h['name'], 'hospital', $h['region'], $h['town'], $h['phone'], $h['email'], $h['address']);
    $hospitalIds[$h['region']][] = $hid;
    echo "  ✓ {$h['name']} (id={$hid})\n";
}

// Also get the 3 ENBTS blood bank IDs for use as recruitment institutions
$bbStmt  = $db->query("SELECT id, region FROM institutions WHERE institution_type='blood_service' ORDER BY id ASC LIMIT 3");
$bbBanks = $bbStmt->fetchAll(PDO::FETCH_KEY_PAIR);

$bbRows      = $db->query("SELECT id, name, region FROM institutions WHERE institution_type='blood_service'")->fetchAll();
$bbByRegion  = [];
foreach ($bbRows as $row) {
    $bbByRegion[$row['region']] = (int) $row['id'];
}

// Fallback: just use the first blood bank for all if regional lookup fails
$fallbackBankId = (int) ($bbRows[0]['id'] ?? $staffId);

function bankForRegion(array $bbByRegion, string $region, int $fallback): int {
    return $bbByRegion[$region] ?? $bbByRegion['Hhohho'] ?? $fallback;
}

// ──────────────────────────────────────────────────────────────────────────────
// DATA TABLES
// ──────────────────────────────────────────────────────────────────────────────

$maleFirstNames = [
    'Sipho','Thabo','Bongani','Nhlanhla','Sifiso','Lungelo','Mduduzi','Siyanda',
    'Thulani','Sibusiso','Sandile','Mpendulo','Ayanda','Sakhile','Bhekani',
    'Mbuso','Ntokozo','Phiwayinkosi','Muzi','Wandile','Mlungisi','Mxolisi',
    'Siphiwe','Senzo','Buyani','Lindani','Mncedisi','Mpho','Lwazi','Simphiwe',
    'Mthokozisi','Bandile','Malibongwe','Sanele','Hlanganani',
];

$femaleFirstNames = [
    'Nokwanda','Nomvula','Lindiwe','Bongiwe','Lungile','Slindile','Thandi',
    'Ntombifuthi','Nokukhanya','Buhle','Zanele','Nompumelelo','Nozipho',
    'Gcinile','Sikhanyisiwe','Sithembile','Nothando','Noxolo','Nontobeko',
    'Ncamsile','Sandisiwe','Khanyisile','Nolwazi','Phindile','Duduzile',
    'Nosipho','Nokufa','Zinhle','Ntombi','Simangele','Hlengiwe','Lungisani',
    'Noluthando','Nozinhle','Nomcebo',
];

$surnames = [
    'Dlamini','Zwane','Simelane','Nkosi','Shabalala','Maseko','Khoza',
    'Magagula','Mthembu','Nxumalo','Fakudze','Vilakati','Mavuso','Mabuza',
    'Gumede','Ndlela','Sikhondze','Mamba','Tsabedze','Tfwala','Dube',
    'Zulu','Hlophe','Motsa','Shongwe','Khumalo','Lukhele','Hleta',
    'Bhembe','Mngomezulu',
];

// Weighted blood type pool (matches Sub-Saharan African distribution)
$bloodTypePool = [
    'O+','O+','O+','O+','O+','O+','O+',   // ~35%
    'A+','A+','A+','A+','A+',              // ~25%
    'B+','B+','B+','B+',                  // ~20%
    'AB+','AB+',                          // ~10%
    'O-',                                 // ~5%
    'A-',                                 // ~3%
    'B-',                                 // ~1.5%
    'AB-',                                // ~0.5%
];

$regionTowns = [
    'Hhohho'     => ["Mbabane","Lobamba","Pigg's Peak","Ezulwini","Bhunya","Ngwenya"],
    'Manzini'    => ['Manzini','Matsapha','Mankayane','Kwaluseni','Ludzeludze','Ngwenya'],
    'Lubombo'    => ['Siteki','Big Bend','Mhlume','Simunye','Tshaneni','Mpaka'],
    'Shiselweni' => ['Nhlangano','Hlathikhulu','Lavumisa','Sigwe','Zombodze','Mahamba'],
];
$regionList = array_keys($regionTowns);

$recruitmentSources = [
    'hospital','hospital','community_campaign','community_campaign',
    'social_media','referral','walk_in','school','university','church','workplace',
];

// ──────────────────────────────────────────────────────────────────────────────
// GENERATE 390 DONOR DEFINITIONS
// ──────────────────────────────────────────────────────────────────────────────
//
// National ID format:  YYMMDD + 7-digit suffix
// Suffix block: 9500001 - 9500400
// Phone base:   +26876500001
// Emergency:    +26876700001

$birthYears  = array_merge(range(1966,1999), range(2000, 2007));
$birthMonths = [1, 3, 5, 7, 9, 11];
$birthDays   = [5, 10, 15, 20, 25];

function birthPartsFor(int $i, array $years, array $months, array $days): array {
    $di  = $i % count($days);
    $mi  = ($i / count($days)) % count($months);
    $yi  = ($i / (count($days) * count($months))) % count($years);
    return [(int) $years[$yi], (int) $months[(int)$mi], (int) $days[$di]];
}

// Status buckets for 390 dummy profiles:
// i   0 - 233 (60%) -> verified + eligible (total_donations >= 1)
// i 234 - 292 (15%) -> verified + temporarily deferred (total_donations >= 1)
// i 293 - 350 (15%) -> pending + not assessed (total_donations = 0)
// i 351 - 370 (5%)  -> verified + permanently deferred (total_donations >= 1)
// i 371 - 389 (5%)  -> verified + not assessed (total_donations >= 1)
function donorStatus(int $i): array {
    if ($i < 234) {
        $dons = 1 + ($i % 8); // 1 to 8 donations
        // last donation was at least 3 months ago (e.g. 3 to 11 months ago)
        $lddOffsetMonths = 3 + ($i % 9);
        $ldd  = date('Y-m-d', strtotime("-{$lddOffsetMonths} months"));
        $ned  = date('Y-m-d', strtotime('+3 months', strtotime($ldd)));
        return [
            'verification_status' => 'verified',
            'eligibility_status' => 'eligible',
            'availability_status' => 'available',
            'total_donations' => $dons,
            'last_donation_date' => $ldd,
            'next_eligible_date' => $ned
        ];
    }
    if ($i < 293) {
        $dons = 1 + ($i % 5); // 1 to 5 donations
        // last donation was recent (e.g. 1 to 2 months ago)
        $lddOffsetMonths = 1 + ($i % 2);
        $ldd  = date('Y-m-d', strtotime("-{$lddOffsetMonths} months"));
        $ned  = date('Y-m-d', strtotime('+1 month'));
        return [
            'verification_status' => 'verified',
            'eligibility_status' => 'temporarily_deferred',
            'availability_status' => 'not_available',
            'total_donations' => $dons,
            'last_donation_date' => $ldd,
            'next_eligible_date' => $ned
        ];
    }
    if ($i < 351) {
        return [
            'verification_status' => 'pending',
            'eligibility_status' => 'not_assessed',
            'availability_status' => 'available',
            'total_donations' => 0,
            'last_donation_date' => null,
            'next_eligible_date' => null
        ];
    }
    if ($i < 371) {
        $dons = 1 + ($i % 4); // 1 to 4 donations
        // last donation was in the past
        $lddOffsetMonths = 4 + ($i % 6);
        $ldd  = date('Y-m-d', strtotime("-{$lddOffsetMonths} months"));
        return [
            'verification_status' => 'verified',
            'eligibility_status' => 'permanently_deferred',
            'availability_status' => 'not_available',
            'total_donations' => $dons,
            'last_donation_date' => $ldd,
            'next_eligible_date' => null
        ];
    }
    // 371 to 389: verified + not_assessed
    $dons = 1 + ($i % 3);
    $lddOffsetMonths = 2 + ($i % 3);
    $ldd  = date('Y-m-d', strtotime("-{$lddOffsetMonths} months"));
    return [
        'verification_status' => 'verified',
        'eligibility_status' => 'not_assessed',
        'availability_status' => 'available',
        'total_donations' => $dons,
        'last_donation_date' => $ldd,
        'next_eligible_date' => null
    ];
}

// ──────────────────────────────────────────────────────────────────────────────
// Truncate/Clean existing donors before seeding to avoid key conflicts
// ──────────────────────────────────────────────────────────────────────────────
echo "Cleaning existing dummy donor accounts...\n";
$db->exec("DELETE FROM users WHERE role = 'donor'");

echo "\nSeeding 390 donors...\n";

$seeded = 0;
for ($i = 0; $i < 390; $i++) {
    $gender   = ($i % 2 === 0) ? 'male' : 'female';
    $fnPool   = $gender === 'male' ? $maleFirstNames : $femaleFirstNames;
    $fname    = $fnPool[$i % count($fnPool)];
    $lname    = $surnames[$i % count($surnames)];
    $fullName = "{$fname} {$lname}";

    // Birth date → national ID
    [$year, $month, $day] = birthPartsFor($i, $birthYears, $birthMonths, $birthDays);
    $yy        = $year % 100;
    $suffix    = 9500001 + $i;
    $nationalId = sprintf('%02d%02d%02d%07d', $yy, $month, $day, $suffix);

    // Phone numbers
    $phoneNum = sprintf('+268%08d', 76500001 + $i);
    $ecPhone  = sprintf('+268%08d', 76700001 + $i);

    // Region / town
    $region = $regionList[$i % count($regionList)];
    $towns  = $regionTowns[$region];
    $town   = $towns[($i / count($regionList)) % count($towns)];

    // Blood type
    $bloodType = $bloodTypePool[$i % count($bloodTypePool)];

    // Recruitment source
    $source = $recruitmentSources[$i % count($recruitmentSources)];

    // Institution
    $institutionId = bankForRegion($bbByRegion, $region, $fallbackBankId);

    $status = donorStatus($i);

    // Calculate realistic registration date (created_at)
    if ($status['total_donations'] > 0 && $status['last_donation_date']) {
        // Oldest donation is ($dons - 1) * 4 months before last donation
        $oldestMonths = ($status['total_donations'] - 1) * 4;
        $regTime = strtotime("-1 month", strtotime("-{$oldestMonths} months", strtotime($status['last_donation_date'])));
        $regDateString = date('Y-m-d H:i:s', $regTime);
    } else {
        $regOffsetDays = ($i * 0.9) + 10;
        $regDateString = date('Y-m-d H:i:s', strtotime("-{$regOffsetDays} days"));
    }

    $donor = array_merge([
        'full_name'             => $fullName,
        'national_id'           => $nationalId,
        'phone'                 => $phoneNum,
        'email'                 => null,
        'gender'                => $gender,
        'region'                => $region,
        'town'                  => $town,
        'blood_type'            => ($status['verification_status'] === 'verified') ? $bloodType : 'Unknown',
        'recruitment_source'    => $source,
        'institution_id'        => $institutionId,
        'emergency_contact_name'  => "Emergency {$lname}",
        'emergency_contact_phone' => $ecPhone,
        'created_at'            => $regDateString,
    ], $status);

    upsertDonor($db, $crypto, $donor, $staffId);
    $seeded++;

    if ($seeded % 50 === 0) {
        echo "  → {$seeded} donors processed...\n";
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// Summary
// ──────────────────────────────────────────────────────────────────────────────

$donorCount = (int) $db->query("SELECT COUNT(*) FROM donor_profiles")->fetchColumn();
$hospCount  = (int) $db->query("SELECT COUNT(*) FROM institutions WHERE institution_type='hospital'")->fetchColumn();

echo "\n";
echo "════════════════════════════════════════════════════════\n";
echo " Seed complete!\n";
echo "────────────────────────────────────────────────────────\n";
echo " Total hospitals in system : {$hospCount}\n";
echo " Total donors  in system   : {$donorCount}\n";
echo "\n";
echo " Status breakdown (seeded batch):\n";
echo "  i   0-233  verified  + eligible             (60%)\n";
echo "  i 234-292  verified  + temporarily deferred (15%)\n";
echo "  i 293-350  pending   + not assessed         (15%)\n";
echo "  i 351-370  verified  + permanently deferred (5%)\n";
echo "  i 371-389  verified  + not assessed         (5%)\n";
echo "\n";
echo " Donor login: National ID + OTP (no passwords set).\n";
echo " Example donor NID: 700105" . sprintf('%07d', 9500001) . "\n";
echo "════════════════════════════════════════════════════════\n";

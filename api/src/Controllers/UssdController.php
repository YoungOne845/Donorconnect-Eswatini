<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

/**
 * UssdController — Simulates a USSD gateway integration for DonorConnect.
 *
 * In production, a USSD gateway (e.g. Africa's Talking) sends HTTP POST
 * callbacks to this endpoint with the following payload:
 *   { sessionId, phoneNumber, serviceCode, text }
 *
 * The `text` field accumulates dial inputs separated by `*`, e.g.:
 *   ""        → Initial dial (*256#)
 *   "1"       → User pressed 1 on the main menu
 *   "1*2"     → User pressed 1, then 2 in subsequent menus
 *
 * Responses begin with:
 *   CON → Continue session (show next menu)
 *   END → Terminate session (final message)
 *
 * This controller is also consumed by the React USSD Simulator page for
 * live demonstration purposes.
 */
final class UssdController
{
    private const SERVICE_CODE = '*256#';

    public function handle(Request $request): never
    {
        $body = $request->json();

        $sessionId   = (string) ($body['sessionId']   ?? 'demo-' . uniqid());
        $phoneNumber = (string) ($body['phoneNumber']  ?? '');
        $text        = (string) ($body['text']         ?? '');

        // Normalize Eswatini phone number format if provided
        if ($phoneNumber !== '') {
            // Validate that phone number does not contain invalid characters/letters
            if (preg_match('/[^0-9\s\+\-\(\)]/', $phoneNumber)) {
                Response::error('Invalid phone number. Phone number must only contain digits and standard symbols.', 422);
            }
            if (!\App\Core\Identity::validEswatiniPhone($phoneNumber)) {
                Response::error('Invalid Eswatini phone number. Phone number must be exactly 8 digits (excluding country code) and start with 2, 6, 7 or 8.', 422);
            }
            $phoneNumber = \App\Core\Identity::phone($phoneNumber);
        }

        $inputs = $text === '' ? [] : explode('*', $text);
        $level  = count($inputs);

        $responseText = $this->route($inputs, $level, $phoneNumber);

        // Log USSD session interaction
        try {
            $db = Database::connection();
            $logStmt = $db->prepare(
                "INSERT INTO ussd_logs (session_id, phone, input_text, response_text)
                 VALUES (:session_id, :phone, :input_text, :response_text)"
            );
            $logStmt->execute([
                'session_id' => $sessionId,
                'phone' => $phoneNumber,
                'input_text' => $text,
                'response_text' => $responseText,
            ]);
        } catch (\Exception $e) {
            // Silently swallow database log failures to ensure uninterrupted USSD service
        }

        // Return as plain-text (USSD standard) or JSON for simulator
        $accept = (string) ($request->header('Accept') ?? $_SERVER['HTTP_ACCEPT'] ?? '');
        $contentType = (string) ($request->header('Content-Type') ?? $_SERVER['CONTENT_TYPE'] ?? '');
        $wantsJson = str_contains($accept, 'application/json') || str_contains($contentType, 'application/json');
        if ($wantsJson) {
            Response::success('USSD response generated.', [
                'sessionId'    => $sessionId,
                'phoneNumber'  => $phoneNumber,
                'response'     => $responseText,
                'continues'    => str_starts_with($responseText, 'CON'),
            ]);
        }

        // Plain-text response for real USSD gateways
        header('Content-Type: text/plain; charset=utf-8');
        echo $responseText;
        exit;
    }

    private function route(array $inputs, int $level, string $phoneNumber): string
    {
        // ── Level 0: Main Menu ────────────────────────────────────────────
        if ($level === 0) {
            return $this->mainMenu();
        }

        $option = $inputs[0];

        return match ($option) {
            '1' => $this->eligibilityMenu($inputs, $level),
            '2' => $this->availabilityMenu($inputs, $level, $phoneNumber),
            '3' => $this->nextDonationDateMenu($inputs, $level, $phoneNumber),
            '4' => $this->emergencyAlerts($phoneNumber),
            '5' => $this->callbackRequestMenu($inputs, $level, $phoneNumber),
            '0' => "END\nThank you for using DonorConnect (" . self::SERVICE_CODE . ").\nVisit donorconnect.sz for more info.",
            default => "END\nInvalid option. Please dial " . self::SERVICE_CODE . " to try again.",
        };
    }

    // ── Main Menu ─────────────────────────────────────────────────────────
    private function mainMenu(): string
    {
        return "CON\nDonorConnect ENBTS " . self::SERVICE_CODE . "\n" .
               "1. Check my eligibility\n" .
               "2. Update my availability\n" .
               "3. My next donation date\n" .
               "4. Emergency blood alerts\n" .
               "5. Request callback / help\n" .
               "0. Exit";
    }

    // ── Option 1: Eligibility Pre-Screen ─────────────────────────────────
    private function eligibilityMenu(array $inputs, int $level): string
    {
        if ($level === 1) {
            return "CON\nEligibility Check:\n" .
                   "Are you 16 years or older and\nweigh at least 50kg?\n" .
                   "1. Yes\n" .
                   "2. No\n" .
                   "0. Back";
        }

        if ($level === 2) {
            return match ($inputs[1]) {
                '1' => $this->eligibilityHealthCheck($inputs, $level),
                '2' => "END\nYou are not yet eligible to donate.\nThe minimum age is 16 years\nand minimum weight is 50 kg.\nKeep up a healthy lifestyle and\ntry again when you qualify!",
                '0' => $this->mainMenu(),
                default => "END\nInvalid selection. Dial " . self::SERVICE_CODE . " to retry.",
            };
        }

        if ($level === 3) {
            return match ($inputs[2]) {
                '1' => "CON\nHave you donated blood\nin the last 2 months?\n1. Yes (male) / 3 months (female)\n2. No - first time or long ago\n0. Back",
                '2' => "END\nPlease consult ENBTS staff before\ndonating if you are ill.\nDonating while sick is not safe.\nVisit Mbabane, Manzini or\nHlathikhulu blood bank.",
                '0' => $this->eligibilityMenu($inputs, 1),
                default => "END\nInvalid input. Dial " . self::SERVICE_CODE . " to retry.",
            };
        }

        if ($level === 4) {
            return match ($inputs[3]) {
                '1' => "END\nYou may need to wait longer.\nMales: 2 months between donations\nFemales: 3 months between donations\nCheck your profile on the app\nfor your exact next eligible date.",
                '2' => "END\nGreat news! You appear ELIGIBLE\nto donate today.\n\nNext step: Visit your nearest\nENBTS clinic or book via the app.\nYour donation saves lives!",
                '0' => $this->eligibilityMenu($inputs, 2),
                default => "END\nInvalid input. Dial " . self::SERVICE_CODE . " to retry.",
            };
        }

        return "END\nSession expired. Dial " . self::SERVICE_CODE . " to start again.";
    }

    private function eligibilityHealthCheck(array $inputs, int $level): string
    {
        return "CON\nAre you currently healthy?\n(No fever, illness or antibiotics\nin the last 2 weeks)\n" .
               "1. Yes, I am healthy\n" .
               "2. No, I am unwell\n" .
               "0. Back";
    }

    // ── Option 2: Update Availability ─────────────────────────────────────
    private function availabilityMenu(array $inputs, int $level, string $phoneNumber): string
    {
        $db = Database::connection();

        // 1. Check if user is registered in the database as a donor
        $userStmt = $db->prepare(
            "SELECT u.id, dp.id AS donor_id, dp.availability_status, u.national_id_encrypted
             FROM users u
             JOIN donor_profiles dp ON dp.user_id = u.id
             WHERE u.phone = :phone AND u.role = 'donor'
             LIMIT 1"
        );
        $userStmt->execute(['phone' => $phoneNumber]);
        $donor = $userStmt->fetch();

        // 2. Unregistered caller flow
        if (!$donor) {
            if ($level === 1) {
                return "CON\nPhone number not found in registry.\nWould you like ENBTS to contact you\nto register?\n1. Yes, call me back\n2. No, exit\n0. Back";
            }

            if ($level === 2) {
                $selection = $inputs[1];
                if ($selection === '1') {
                    $stmt = $db->prepare(
                        "INSERT INTO ussd_requests (phone, request_type, status, notes)
                         VALUES (:phone, 'registration_request', 'pending', :notes)"
                    );
                    $stmt->execute([
                        'phone' => $phoneNumber,
                        'notes' => 'Registration request via USSD Option 2 (Availability)'
                    ]);
                    return "END\nRequest logged.\nAn ENBTS agent will call\nyou at {$phoneNumber} to register.";
                }

                if ($selection === '2') {
                    return "END\nThank you for using DonorConnect.\nVisit donorconnect.sz for more details.";
                }

                if ($selection === '0') {
                    return $this->mainMenu();
                }

                return "END\nInvalid option. Dial " . self::SERVICE_CODE . " to retry.";
            }

            return "END\nSession error. Dial " . self::SERVICE_CODE . " to try again.";
        }

        // 3. Registered donor flow (with National ID authentication)
        // Level 1: Ask for National ID
        if ($level === 1) {
            return "CON\nWelcome to DonorConnect.\nPlease enter your 13-digit National ID\nto verify your identity:\n(Enter 0 to go back)";
        }

        // Level 2: Check National ID and display toggle menu if verified
        if ($level === 2) {
            $inputNid = trim($inputs[1]);
            if ($inputNid === '0') {
                return $this->mainMenu();
            }

            // Verify National ID matches
            try {
                $decryptedNid = \App\Core\App::crypto()->decrypt($donor['national_id_encrypted']);
                if ($decryptedNid !== $inputNid) {
                    return "END\nVerification failed.\nThe National ID entered does not match\nour records. Please try again.";
                }
            } catch (\Exception $e) {
                return "END\nVerification failed.\nIdentity decryption error. Contact support.";
            }

            $currentStatus = strtoupper(str_replace('_', ' ', (string) $donor['availability_status']));
            return "CON\nIdentity verified!\nUpdate Availability:\nYour current status: {$currentStatus}\n" .
                   "1. I am AVAILABLE to donate\n" .
                   "2. I am NOT available now\n" .
                   "0. Back";
        }

        // Level 3: Perform availability toggle (if verified in previous step)
        if ($level === 3) {
            // Re-verify NID in case of session manipulation
            $inputNid = trim($inputs[1]);
            try {
                $decryptedNid = \App\Core\App::crypto()->decrypt($donor['national_id_encrypted']);
                if ($decryptedNid !== $inputNid) {
                    return "END\nVerification failed.\nSession authenticity error.";
                }
            } catch (\Exception $e) {
                return "END\nVerification failed.\nSession decryption error.";
            }

            $selection = $inputs[2];
            if ($selection === '0') {
                return $this->mainMenu();
            }

            if ($selection === '1' || $selection === '2') {
                $newStatus = $selection === '1' ? 'available' : 'not_available';
                $label = $selection === '1' ? 'AVAILABLE' : 'NOT AVAILABLE';

                $stmt = $db->prepare(
                    "UPDATE donor_profiles
                     SET availability_status = :status
                     WHERE id = :id"
                );
                $stmt->execute(['status' => $newStatus, 'id' => $donor['donor_id']]);

                // Create a record in donor_activity_logs with USSD source tag
                try {
                    $activityStmt = $db->prepare(
                        "INSERT INTO donor_activity_logs (donor_id, activity_type, description, metadata)
                         VALUES (:donor_id, 'availability_updated', :description, :metadata)"
                    );
                    $activityStmt->execute([
                        'donor_id' => $donor['donor_id'],
                        'description' => "Availability updated to {$newStatus} via USSD (" . self::SERVICE_CODE . ")",
                        'metadata' => json_encode(['source' => 'ussd', 'status' => $newStatus])
                    ]);
                } catch (\Exception $e) {
                    // Silently fail activity logging if needed
                }

                return "END\nStatus updated to {$label}.\n" .
                       ($newStatus === 'available'
                           ? "Thank you! You may be contacted\nfor a critical blood request.\nEvery donation saves lives!"
                           : "Understood. We will not contact\nyou for requests right now.\nUpdate anytime via " . self::SERVICE_CODE . ".");
            }

            return "END\nInvalid option. Dial " . self::SERVICE_CODE . " to retry.";
        }

        return "END\nSession error. Dial " . self::SERVICE_CODE . " to try again.";
    }

    // ── Option 3: Next Donation Date ──────────────────────────────────────
    private function nextDonationDateMenu(array $inputs, int $level, string $phoneNumber): string
    {
        $db = Database::connection();

        $stmt = $db->prepare(
            "SELECT dp.next_eligible_date, dp.availability_status, u.full_name, u.national_id_encrypted
             FROM users u
             JOIN donor_profiles dp ON dp.user_id = u.id
             WHERE u.phone = :phone AND u.role = 'donor'
             LIMIT 1"
        );
        $stmt->execute(['phone' => $phoneNumber]);
        $row = $stmt->fetch();

        // Unregistered user logic
        if (!$row) {
            if ($level === 1) {
                return "CON\nPhone number not found in registry.\nWould you like ENBTS to contact you\nto register?\n1. Yes, call me back\n2. No, exit\n0. Back";
            }

            if ($level === 2) {
                $selection = $inputs[1];
                if ($selection === '1') {
                    $stmt2 = $db->prepare(
                        "INSERT INTO ussd_requests (phone, request_type, status, notes)
                         VALUES (:phone, 'registration_request', 'pending', :notes)"
                    );
                    $stmt2->execute([
                        'phone' => $phoneNumber,
                        'notes' => 'Registration request via USSD Option 3 (Next Donation Date)'
                    ]);
                    return "END\nRequest logged.\nAn ENBTS agent will call\nyou at {$phoneNumber} to register.";
                }

                if ($selection === '2') {
                    return "END\nThank you for using DonorConnect.\nVisit donorconnect.sz for more details.";
                }

                if ($selection === '0') {
                    return $this->mainMenu();
                }

                return "END\nInvalid option. Dial " . self::SERVICE_CODE . " to retry.";
            }

            return "END\nSession error. Dial " . self::SERVICE_CODE . " to try again.";
        }

        // Registered user logic
        // Level 1: Ask for National ID
        if ($level === 1) {
            return "CON\nWelcome to DonorConnect.\nPlease enter your 13-digit National ID\nto verify your identity:\n(Enter 0 to go back)";
        }

        // Level 2: Verify ID and return result
        if ($level === 2) {
            $inputNid = trim($inputs[1]);
            if ($inputNid === '0') {
                return $this->mainMenu();
            }

            // Verify National ID matches
            try {
                $decryptedNid = \App\Core\App::crypto()->decrypt($row['national_id_encrypted']);
                if ($decryptedNid !== $inputNid) {
                    return "END\nVerification failed.\nThe National ID entered does not match\nour records. Please try again.";
                }
            } catch (\Exception $e) {
                return "END\nVerification failed.\nIdentity decryption error. Contact support.";
            }

            $name = explode(' ', (string) $row['full_name'])[0];
            $date = $row['next_eligible_date']
                ? date('d M Y', strtotime((string) $row['next_eligible_date']))
                : 'Not set yet';

            $daysStr = '';
            if ($row['next_eligible_date']) {
                $now = new \DateTime('today');
                $eligibleDate = new \DateTime($row['next_eligible_date']);
                if ($eligibleDate > $now) {
                    $days = $now->diff($eligibleDate)->days;
                    $daysStr = "\n({$days} days remaining)";
                } else {
                    $daysStr = "\n(You are eligible now!)";
                }
            }

            $statusLabel = $row['availability_status'] === 'available' ? 'Available' : 'Not available';

            return "END\nHello {$name}!\nNext eligible donation: {$date}{$daysStr}\nCurrent status: {$statusLabel}\n\nUpdate your status anytime\nby dialling " . self::SERVICE_CODE . " > Option 2.";
        }

        return "END\nSession error. Dial " . self::SERVICE_CODE . " to try again.";
    }

    // ── Option 4: Emergency Alerts ────────────────────────────────────────
    private function emergencyAlerts(string $phoneNumber): string
    {
        $db = Database::connection();

        // Find critical inventory levels
        $stmt = $db->prepare(
            "SELECT bi.blood_type, bi.available_units, bi.critical_threshold, i.name AS bank_name, i.town
             FROM blood_inventory bi
             JOIN institutions i ON i.id = bi.institution_id
             WHERE bi.critical_threshold > 0 AND bi.available_units <= bi.critical_threshold
               AND i.institution_type = 'blood_service'
             ORDER BY bi.available_units ASC
             LIMIT 3"
        );
        $stmt->execute();
        $criticals = $stmt->fetchAll();

        if (empty($criticals)) {
            return "END\nNo critical shortages right now.\nAll blood banks currently have\nadequate stock levels.\nThank you for checking!";
        }

        $lines = ["END\nCRITICAL ALERTS (" . $this->today() . "):"];
        foreach ($criticals as $c) {
            $lines[] = "{$c['blood_type']} - {$c['bank_name']}: {$c['available_units']} units";
        }
        $lines[] = "\nIf you can donate, visit your\nnearest ENBTS clinic urgently.\nYour help is needed NOW!";

        return implode("\n", $lines);
    }

    // ── Option 5: Request Callback / Help Menu ─────────────────────────────
    private function callbackRequestMenu(array $inputs, int $level, string $phoneNumber): string
    {
        $db = Database::connection();

        $stmt = $db->prepare(
            "SELECT dp.id AS donor_id, u.national_id_encrypted
             FROM users u
             JOIN donor_profiles dp ON dp.user_id = u.id
             WHERE u.phone = :phone AND u.role = 'donor'
             LIMIT 1"
        );
        $stmt->execute(['phone' => $phoneNumber]);
        $donor = $stmt->fetch();

        // Level 1: Ask for National ID (if registered)
        if ($donor) {
            if ($level === 1) {
                return "CON\nWelcome to DonorConnect.\nPlease enter your 13-digit National ID\nto verify your identity:\n(Enter 0 to go back)";
            }

            if ($level === 2) {
                $inputNid = trim($inputs[1]);
                if ($inputNid === '0') {
                    return $this->mainMenu();
                }

                // Verify National ID matches
                try {
                    $decryptedNid = \App\Core\App::crypto()->decrypt($donor['national_id_encrypted']);
                    if ($decryptedNid !== $inputNid) {
                        return "END\nVerification failed.\nThe National ID entered does not match\nour records. Please try again.";
                    }
                } catch (\Exception $e) {
                    return "END\nVerification failed.\nIdentity decryption error. Contact support.";
                }

                return "CON\nIdentity verified!\nRequest Callback / Help:\n" .
                       "Select your issue:\n" .
                       "1. Blood request query\n" .
                       "2. Profile update assistance\n" .
                       "3. General inquiry\n" .
                       "0. Back";
            }

            if ($level === 3) {
                // Re-verify NID
                $inputNid = trim($inputs[1]);
                try {
                    $decryptedNid = \App\Core\App::crypto()->decrypt($donor['national_id_encrypted']);
                    if ($decryptedNid !== $inputNid) {
                        return "END\nVerification failed.\nSession authenticity error.";
                    }
                } catch (\Exception $e) {
                    return "END\nVerification failed.\nSession decryption error.";
                }

                $selection = $inputs[2];
                if ($selection === '0') {
                    return $this->mainMenu();
                }

                $issues = [
                    '1' => 'Blood request query',
                    '2' => 'Profile update assistance',
                    '3' => 'General inquiry'
                ];

                if (isset($issues[$selection])) {
                    $issueLabel = $issues[$selection];

                    $stmt = $db->prepare(
                        "INSERT INTO ussd_requests (phone, request_type, status, notes)
                         VALUES (:phone, 'callback_request', 'pending', :notes)"
                    );
                    $stmt->execute([
                        'phone' => $phoneNumber,
                        'notes' => 'Callback request: ' . $issueLabel
                    ]);

                    return "END\nRequest logged.\nAn ENBTS agent will call\nyou at {$phoneNumber} shortly.";
                }

                return "END\nInvalid selection. Dial " . self::SERVICE_CODE . " to retry.";
            }
        } else {
            // Unregistered user requesting callback
            if ($level === 1) {
                return "CON\nRequest Callback / Help:\n" .
                       "Select your issue:\n" .
                       "1. Register as a donor\n" .
                       "2. General inquiry\n" .
                       "0. Back";
            }

            if ($level === 2) {
                $selection = $inputs[1];
                if ($selection === '0') {
                    return $this->mainMenu();
                }

                $issues = [
                    '1' => 'Registration request via Option 5',
                    '2' => 'General inquiry (unregistered)'
                ];

                if (isset($issues[$selection])) {
                    $issueLabel = $issues[$selection];
                    $reqType = $selection === '1' ? 'registration_request' : 'callback_request';

                    $stmt = $db->prepare(
                        "INSERT INTO ussd_requests (phone, request_type, status, notes)
                         VALUES (:phone, :type, 'pending', :notes)"
                    );
                    $stmt->execute([
                        'phone' => $phoneNumber,
                        'type' => $reqType,
                        'notes' => $issueLabel
                    ]);

                    return "END\nRequest logged.\nAn ENBTS agent will call\nyou at {$phoneNumber} shortly.";
                }

                return "END\nInvalid selection. Dial " . self::SERVICE_CODE . " to retry.";
            }
        }

        return "END\nSession error. Dial " . self::SERVICE_CODE . " to try again.";
    }

    private function today(): string
    {
        return date('d M Y');
    }

    // ── Admin Portal API Endpoints ─────────────────────────────────────────
    public function getRequests(Request $request): never
    {
        \App\Core\App::auth()->requireRoles(['staff', 'admin']);
        $db = Database::connection();

        // 1. Fetch USSD requests with matching donor name if registered
        $requestsStmt = $db->query(
            "SELECT r.id, r.phone, r.request_type, r.status, r.notes, r.created_at, r.updated_at, u.full_name AS donor_name
             FROM ussd_requests r
             LEFT JOIN users u ON u.phone = r.phone AND u.role = 'donor'
             ORDER BY r.created_at DESC"
        );
        $requests = $requestsStmt->fetchAll();

        // 2. Fetch recent USSD availability updates from donor_activity_logs
        $updatesStmt = $db->query(
            "SELECT dal.id, dal.donor_id, dal.description, dal.created_at, u.full_name AS donor_name, u.phone
             FROM donor_activity_logs dal
             JOIN donor_profiles dp ON dp.id = dal.donor_id
             JOIN users u ON u.id = dp.user_id
             WHERE dal.activity_type = 'availability_updated' AND dal.metadata LIKE '%\"source\":\"ussd\"%'
             ORDER BY dal.created_at DESC
             LIMIT 50"
        );
        $availabilityUpdates = $updatesStmt->fetchAll();

        // 3. Fetch recent USSD session audit logs
        $logsStmt = $db->query(
            "SELECT l.id, l.session_id, l.phone, l.input_text, l.response_text, l.created_at, u.full_name AS donor_name
             FROM ussd_logs l
             LEFT JOIN users u ON u.phone = l.phone AND u.role = 'donor'
             ORDER BY l.created_at DESC
             LIMIT 50"
        );
        $logs = $logsStmt->fetchAll();

        Response::success('USSD portal data loaded.', [
            'requests' => $requests,
            'availability_updates' => $availabilityUpdates,
            'logs' => $logs
        ]);
    }

    public function updateRequestStatus(Request $request): never
    {
        \App\Core\App::auth()->requireRoles(['staff', 'admin']);
        $id = (int) $request->param('id');
        $body = $request->json();

        $status = (string) ($body['status'] ?? '');
        if ($status !== 'pending' && $status !== 'resolved') {
            throw new \App\Core\HttpException(422, 'Invalid status. Status must be pending or resolved.');
        }

        $db = Database::connection();

        // Verify request exists
        $checkStmt = $db->prepare("SELECT id, phone, request_type, status, notes FROM ussd_requests WHERE id = :id LIMIT 1");
        $checkStmt->execute(['id' => $id]);
        $ussdRequest = $checkStmt->fetch();
        if (!$ussdRequest) {
            throw new \App\Core\HttpException(404, 'USSD request not found.');
        }

        $oldStatus = $ussdRequest['status'];

        $stmt = $db->prepare("UPDATE ussd_requests SET status = :status WHERE id = :id");
        $stmt->execute(['status' => $status, 'id' => $id]);

        // Audit log status change
        \App\Core\Audit::log('USSD_REQUEST_STATUS_UPDATED', "USSD request #{$id} status updated to {$status}", 'ussd_requests', $id, null, ['status' => $status], $request);

        // If status changed to resolved, dispatch notification & SMS
        if ($status === 'resolved' && $oldStatus !== 'resolved') {
            // Check if donor is registered
            $userStmt = $db->prepare(
                "SELECT id, full_name FROM users WHERE phone = :phone AND role = 'donor' LIMIT 1"
            );
            $userStmt->execute(['phone' => $ussdRequest['phone']]);
            $user = $userStmt->fetch();

            $smsMsg = "";
            if ($user) {
                $firstName = explode(' ', (string) $user['full_name'])[0];
                if ($ussdRequest['request_type'] === 'registration_request') {
                    $smsMsg = "Hello {$firstName}, your registration request on DonorConnect has been processed. You can now access services.";
                } else {
                    $smsMsg = "Hello {$firstName}, your callback request regarding '" . ($ussdRequest['notes'] ?? 'help') . "' has been resolved by our staff.";
                }

                try {
                    $notificationService = new \App\Services\NotificationService();
                    $notificationService->create(
                        (int) $user['id'],
                        'system',
                        'USSD Request Resolved',
                        $smsMsg,
                        '/app/profile',
                        null,
                        null,
                        true // also send SMS
                    );
                } catch (\Exception $e) {
                    // Silently fail notification dispatch to not crash the request resolve
                }
            } else {
                // Unregistered user
                if ($ussdRequest['request_type'] === 'registration_request') {
                    $smsMsg = "Hello, your registration request on DonorConnect has been processed. Please dial *256# to check eligibility and register.";
                } else {
                    $smsMsg = "Hello, your callback request on DonorConnect has been resolved by our staff. Thank you.";
                }

                try {
                    $smsService = new \App\Services\SmsService();
                    $smsService->send(null, $ussdRequest['phone'], $smsMsg);
                } catch (\Exception $e) {
                    // Silently fail SMS dispatch
                }
            }
        }

        Response::success('USSD request status updated.');
    }
}

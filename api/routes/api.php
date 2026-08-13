<?php

declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\CampaignController;
use App\Controllers\DashboardController;
use App\Controllers\ENBTSController;
use App\Controllers\DonorController;
use App\Controllers\NotificationController;
use App\Controllers\ReportController;
use App\Controllers\RequestController;
use App\Controllers\SetupController;
use App\Controllers\StaffController;
use App\Controllers\UssdController;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;

$router = new Router();
$auth = new AuthController();
$donor = new DonorController();
$staff = new StaffController();
$requests = new RequestController();
$campaigns = new CampaignController();
$notifications = new NotificationController();
$reports = new ReportController();
$admin = new AdminController();
$dashboard = new DashboardController();
$enbts = new ENBTSController();
$setup = new SetupController();
$ussd  = new UssdController();

$router->get('/health', static function (Request $request): never {
    Database::connection()->query('SELECT 1');
    Response::success('DonorConnect API is healthy.', [
        'service' => 'DonorConnect API',
        'version' => '1.0.0',
        'database' => 'connected',
        'server_time' => date(DATE_ATOM),
    ]);
});

$router->get('/auth/csrf', [$auth, 'csrf']);
$router->post('/auth/register', [$auth, 'register']);
$router->post('/auth/login', [$auth, 'login']);
$router->post('/auth/otp/request', [$auth, 'requestOtp']);
$router->post('/auth/otp/verify', [$auth, 'verifyOtp']);
$router->get('/auth/me', [$auth, 'me']);
$router->post('/auth/logout', [$auth, 'logout'], ['auth' => true, 'csrf' => true]);

$router->post('/auth/forgot-password/request', [$auth, 'forgotPasswordRequest']);
$router->post('/auth/forgot-password/send', [$auth, 'forgotPasswordSend']);
$router->post('/auth/forgot-password/reset', [$auth, 'forgotPasswordReset']);

$router->post('/setup/admin', [$setup, 'createFirstAdmin']);

$router->get('/institutions', [$admin, 'institutions']);
$router->post('/institutions', [$admin, 'createInstitution'], ['auth' => true, 'roles' => ['admin'], 'csrf' => true]);
$router->delete('/institutions/{id}', [$admin, 'deleteInstitution'], ['auth' => true, 'roles' => ['admin'], 'csrf' => true]);

$router->get('/dashboard', [$dashboard, 'index'], ['auth' => true]);

$router->get('/donor/profile', [$donor, 'profile'], ['auth' => true, 'roles' => ['donor']]);
$router->put('/donor/profile', [$donor, 'updateProfile'], ['auth' => true, 'roles' => ['donor'], 'csrf' => true]);
$router->patch('/donor/availability', [$donor, 'updateAvailability'], ['auth' => true, 'roles' => ['donor'], 'csrf' => true]);
$router->post('/donor/password', [$auth, 'updatePassword'], ['auth' => true, 'roles' => ['donor'], 'csrf' => true]);
$router->post('/donor/verify-password', [$donor, 'verifyPassword'], ['auth' => true, 'roles' => ['donor'], 'csrf' => true]);
$router->get('/donor/profile-update-requests', [$donor, 'profileUpdateRequests'], ['auth' => true, 'roles' => ['donor']]);
$router->post('/donor/profile-update-request', [$donor, 'requestProfileUpdate'], ['auth' => true, 'roles' => ['donor'], 'csrf' => true]);
$router->get('/donor/appointments', [$donor, 'appointments'], ['auth' => true, 'roles' => ['donor']]);
$router->post('/donor/appointments', [$donor, 'bookAppointment'], ['auth' => true, 'roles' => ['donor'], 'csrf' => true]);
$router->get('/donor/activity', [$donor, 'activity'], ['auth' => true, 'roles' => ['donor']]);
$router->post('/donor/matches/{matchId}/respond', [$donor, 'respondToRequest'], ['auth' => true, 'roles' => ['donor'], 'csrf' => true]);

$router->get('/notifications', [$notifications, 'index'], ['auth' => true]);
$router->get('/notifications/count', [$notifications, 'unreadCount'], ['auth' => true]);
$router->get('/notifications/recent', [$notifications, 'recent'], ['auth' => true]);
$router->post('/sms/test', [$notifications, 'testSms'], ['auth' => true, 'roles' => ['hospital','staff','admin'], 'csrf' => true]);
$router->patch('/notifications/{id}/read', [$notifications, 'markRead'], ['auth' => true, 'csrf' => true]);
$router->patch('/notifications/read-all', [$notifications, 'markAllRead'], ['auth' => true, 'csrf' => true]);
$router->delete('/notifications', [$notifications, 'deleteAll'], ['auth' => true, 'csrf' => true]);
$router->delete('/notifications/{id}', [$notifications, 'delete'], ['auth' => true, 'csrf' => true]);

$router->get('/appointments', [$staff, 'appointments'], ['auth' => true, 'roles' => ['staff','admin']]);
$router->patch('/appointments/{id}/review', [$staff, 'reviewAppointment'], ['auth' => true, 'roles' => ['staff','admin'], 'csrf' => true]);
$router->get('/donors', [$staff, 'donors'], ['auth' => true, 'roles' => ['staff','admin']]);
$router->post('/donors', [$staff, 'createDonor'], ['auth' => true, 'roles' => ['staff','admin'], 'csrf' => true]);
$router->post('/donors/messages', [$staff, 'sendDonorMessages'], ['auth' => true, 'roles' => ['admin'], 'csrf' => true]);
$router->get('/donors/{id}', [$staff, 'donor'], ['auth' => true, 'roles' => ['staff','admin']]);
$router->get('/donors/{id}/profile-update-requests', [$staff, 'profileUpdateRequests'], ['auth' => true, 'roles' => ['staff','admin']]);
$router->patch('/profile-update-requests/{id}/review', [$staff, 'reviewProfileUpdateRequest'], ['auth' => true, 'roles' => ['staff','admin'], 'csrf' => true]);
$router->patch('/donors/{id}/verify', [$staff, 'verify'], ['auth' => true, 'roles' => ['staff','admin'], 'csrf' => true]);
$router->post('/donors/{id}/eligibility', [$staff, 'assessEligibility'], ['auth' => true, 'roles' => ['staff','admin'], 'csrf' => true]);
$router->post('/donors/{id}/donations', [$staff, 'recordDonation'], ['auth' => true, 'roles' => ['staff','admin'], 'csrf' => true]);
$router->post('/donors/{id}/deferrals', [$staff, 'createDeferral'], ['auth' => true, 'roles' => ['staff','admin'], 'csrf' => true]);
$router->patch('/deferrals/{id}/close', [$staff, 'closeDeferral'], ['auth' => true, 'roles' => ['staff','admin'], 'csrf' => true]);

$router->get('/requests', [$requests, 'index'], ['auth' => true, 'roles' => ['hospital','staff','admin']]);
$router->post('/requests', [$requests, 'create'], ['auth' => true, 'roles' => ['hospital','admin'], 'csrf' => true]);
$router->get('/requests/{id}', [$requests, 'show'], ['auth' => true, 'roles' => ['hospital','staff','admin']]);
$router->post('/requests/{id}/match', [$requests, 'match'], ['auth' => true, 'roles' => ['admin'], 'csrf' => true]);
$router->post('/requests/{id}/notify', [$requests, 'notify'], ['auth' => true, 'roles' => ['admin'], 'csrf' => true]);
$router->patch('/requests/{id}/status', [$requests, 'updateStatus'], ['auth' => true, 'roles' => ['hospital','admin'], 'csrf' => true]);

// Hospital patient lookup — identify an unconscious patient by national ID
$router->get('/hospital/patient-lookup', [$enbts, 'patientLookup'], ['auth' => true, 'roles' => ['hospital','staff','admin']]);

$router->get('/campaigns', [$campaigns, 'index'], ['auth' => true]);
$router->post('/campaigns', [$campaigns, 'create'], ['auth' => true, 'roles' => ['admin'], 'csrf' => true]);
$router->patch('/campaigns/{id}/status', [$campaigns, 'updateStatus'], ['auth' => true, 'roles' => ['admin'], 'csrf' => true]);
$router->post('/campaigns/{id}/join', [$campaigns, 'join'], ['auth' => true, 'roles' => ['donor'], 'csrf' => true]);
$router->post('/campaigns/{id}/invite', [$campaigns, 'invite'], ['auth' => true, 'roles' => ['admin'], 'csrf' => true]);

$router->get('/reports/overview', [$reports, 'overview'], ['auth' => true, 'roles' => ['staff','admin']]);

$router->get('/enbts/inventory', [$enbts, 'inventory'], ['auth' => true, 'roles' => ['staff','admin']]);
$router->put('/enbts/inventory', [$enbts, 'updateInventory'], ['auth' => true, 'roles' => ['staff','admin'], 'csrf' => true]);
$router->get('/enbts/dispatches', [$enbts, 'dispatches'], ['auth' => true, 'roles' => ['hospital','staff','admin']]);
$router->post('/enbts/dispatches', [$enbts, 'createDispatch'], ['auth' => true, 'roles' => ['admin'], 'csrf' => true]);
$router->patch('/enbts/dispatches/{id}/status', [$enbts, 'updateDispatchStatus'], ['auth' => true, 'roles' => ['staff','admin'], 'csrf' => true]);
$router->get('/enbts/campaign-requests', [$enbts, 'campaignRequests'], ['auth' => true, 'roles' => ['staff','admin']]);
$router->post('/enbts/campaign-requests', [$enbts, 'createCampaignRequest'], ['auth' => true, 'roles' => ['staff'], 'csrf' => true]);
$router->patch('/enbts/campaign-requests/{id}/review', [$enbts, 'reviewCampaignRequest'], ['auth' => true, 'roles' => ['admin'], 'csrf' => true]);

// USSD Gateway endpoint — receives callbacks from Africa's Talking / USSD bridge
// Also used by the React USSD Simulator for live demonstration
$router->post('/ussd', [$ussd, 'handle']);

// USSD Admin Portal routes
$router->get('/admin/ussd/requests', [$ussd, 'getRequests'], ['auth' => true, 'roles' => ['staff', 'admin']]);
$router->patch('/admin/ussd/requests/{id}', [$ussd, 'updateRequestStatus'], ['auth' => true, 'roles' => ['staff', 'admin'], 'csrf' => true]);


$router->get('/admin/users', [$admin, 'users'], ['auth' => true, 'roles' => ['admin']]);
$router->post('/admin/users', [$admin, 'createStaffAccount'], ['auth' => true, 'roles' => ['admin'], 'csrf' => true]);
$router->patch('/admin/users/{id}/status', [$admin, 'updateUserStatus'], ['auth' => true, 'roles' => ['admin'], 'csrf' => true]);
$router->delete('/admin/users/{id}', [$admin, 'deleteUser'], ['auth' => true, 'roles' => ['admin'], 'csrf' => true]);

return $router;

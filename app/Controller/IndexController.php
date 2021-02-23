<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
namespace App\Controller;

use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Hyperf\View\RenderInterface;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\RequestMapping;
use Hyperf\HttpServer\Annotation\Controller;
use Psr\Http\Message\ResponseInterface as Response;
use App\Middleware\AdminLoginRequiredMiddleware;
use App\Middleware\LoginRequiredMiddleware;
use App\Service\UserService;

/**
 * @Controller()
 */
class IndexController extends AbstractController
{
    /**
     *
     * @Inject()
     * @var UserService
     */

    private $userService;
    /**
     * @Inject()
     * @var \Hyperf\Contract\SessionInterface
     */
    private $session;

    private function validate_rank($rank)
    {
        return (int) Db::select('SELECT COUNT(*) AS `CNT` FROM sheets WHERE `rank` = ?', [$rank])[0]['CNT'] ?? 0;
    }

    private function sanitize_event(array $event): array
    {
        unset($event['price']);
        unset($event['public']);
        unset($event['closed']);
    
        return $event;
    }
    
    private function res_error(ResponseInterface $response, string $error = 'unknown', int $status = 500): Response
    {
        return $response->json(['error' => $error], $status);
    }

    private function get_events(?callable $where = null): array
    {
        if (null === $where) {
            $where = function (array $event) {
                return $event['public_fg'];
            };
        }
    
        // Db::beginTransaction();
        try {
            $events = [];
            $event_ids = array_map(function (array $event) {
                return $event['id'];
            }, array_filter(Db::select('SELECT * FROM events ORDER BY id ASC'), $where));
    
            foreach ($event_ids as $event_id) {
                $event = $this->get_event($event_id);
        
                foreach (array_keys($event['sheets']) as $rank) {
                    unset($event['sheets'][$rank]['detail']);
                }
        
                array_push($events, $event);
            }
        } catch (\Throwable $e) {
            // Db::rollback();
        }
        // Db::commit();

        return $events;
    }
    private function get_event(int $event_id, ?int $login_user_id = null): array
    {
        $event = Db::select('SELECT * FROM events WHERE id = ?', [$event_id])[0] ?? null;


        if (!$event) {
            return [];
        }
    
        $event['id'] = (int) $event['id'];
    
        // zero fill
        $event['total'] = 0;
        $event['remains'] = 0;
    
        foreach (['S', 'A', 'B', 'C'] as $rank) {
            $event['sheets'][$rank]['total'] = 0;
            $event['sheets'][$rank]['remains'] = 0;
        }
    
        $sheets = Db::select('SELECT * FROM sheets ORDER BY `rank`, num');

        foreach ($sheets as $sheet) {
            $event['sheets'][$sheet['rank']]['price'] = $event['sheet'][$sheet['rank']]['price'] ?? $event['price'] + $sheet['price'];
    
            ++$event['total'];
            ++$event['sheets'][$sheet['rank']]['total'];
    
            $reservation =  Db::select('SELECT * FROM reservations WHERE event_id = ? AND sheet_id = ? AND canceled_at IS NULL GROUP BY event_id, sheet_id HAVING reserved_at = MIN(reserved_at)', [$event['id'], $sheet['id']])[0] ?? null;
            if ($reservation) {
                $sheet['mine'] = $login_user_id && $reservation['user_id'] == $login_user_id;
                $sheet['reserved'] = true;
                $sheet['reserved_at'] = (new \DateTime("{$reservation['reserved_at']}", new \DateTimeZone('UTC')))->getTimestamp();
            } else {
                ++$event['remains'];
                ++$event['sheets'][$sheet['rank']]['remains'];
            }

            $sheet['num'] = $sheet['num'];
            $rank = $sheet['rank'];
            unset($sheet['id']);
            unset($sheet['price']);
            unset($sheet['rank']);
    
            if (false === isset($event['sheets'][$rank]['detail'])) {
                $event['sheets'][$rank]['detail'] = [];
            }
            array_push($event['sheets'][$rank]['detail'], $sheet);
        }
    
        $event['public'] = $event['public_fg'] ? true : false;
        $event['closed'] = $event['closed_fg'] ? true : false;
    
        unset($event['public_fg']);
        unset($event['closed_fg']);
    
        return $event;
    }
    
    /**
     * @RequestMapping("/")
     * @return Response
     */
    public function index(): Response
    {
        $user = $this->userService->getLoginUser();

        $headers = $this->request->getHeaders();
        $events = array_map(function (array $event) {
            return $this->sanitize_event($event);
        }, $this->get_events());
            
        $baseUrl =  ($headers['x-forwarded-proto'][0] ?? 'http') . '://' . $headers['host'][0];
        return $this->render->render('index.twig', [
            'events' => $events,
            'base_url' => $baseUrl,
            'user' => $user,
        ]);
    }

    /**
     * @RequestMapping("/initialize")
     * @return Response
     */
    public function initialize(): Response
    {
        exec('./storage/db/init.sh');
        return $this->response->withStatus(204);
    }

    /**
     * @RequestMapping(path="/api/users", methods="post")
     * @return Response
     */
    public function apiUsers(): Response
    {
        $nickname = $this->request->input('nickname');
        $login_name = $this->request->input('login_name');
        $password = $this->request->input('password');
    
        $user_id = null;
    
    
        $duplicated = Db::select('SELECT * FROM users WHERE login_name = ?', [$login_name]);
        if ($duplicated) {
            // Db::rollback();

            return $this->res_error($this->response, 'duplicated', 409);
        }

        Db::beginTransaction();
        try {
            Db::insert('INSERT INTO users (login_name, pass_hash, nickname) VALUES (?, SHA2(?, 256), ?)', [$login_name, $password, $nickname]);
            $user_id = Db::select('SELECT last_insert_id() as user_id')[0]['user_id'];
            Db::commit();
        } catch (\Throwable $throwable) {
            Db::rollback();
    
            return $this->res_error($this->response);
        }
    
        return $this->response->json([
            'id' => (int)$user_id,
            'nickname' => $nickname,
        ], 201, JSON_NUMERIC_CHECK);
    }

    /**
     * @RequestMapping(path="/api/users/{id}", methods="get")
     * @Middleware(LoginRequiredMiddleware::class)
     * @param int    $id
     * @return Response
     */
    public function apiUsersById(int $id): Response
    {
        $user = Db::select('SELECT id, nickname FROM users WHERE id = ?', [$id])[0];
        $user['id'] = (int) $user['id'];
        if (!$user || $user['id'] !== $this->userService->getLoginUser()['id']) {
            return $this->res_error($this->response, 'forbidden', 403);
        }
    
        $recent_reservations = function () use ($user) {
            $recent_reservations = [];
    
            $rows = Db::select('SELECT r.*, s.rank AS sheet_rank, s.num AS sheet_num FROM reservations r INNER JOIN sheets s ON s.id = r.sheet_id WHERE r.user_id = ? ORDER BY IFNULL(r.canceled_at, r.reserved_at) DESC LIMIT 5', [$user['id']]);
            foreach ($rows as $row) {
                $event = $this->get_event($row['event_id']);
                $price = $event['sheets'][$row['sheet_rank']]['price'];
                unset($event['sheets']);
                unset($event['total']);
                unset($event['remains']);
    
                $reservation = [
                    'id' => $row['id'],
                    'event' => $event,
                    'sheet_rank' => $row['sheet_rank'],
                    'sheet_num' => $row['sheet_num'],
                    'price' => $price,
                    'reserved_at' => (new \DateTime("{$row['reserved_at']}", new \DateTimeZone('UTC')))->getTimestamp(),
                ];
    
                if ($row['canceled_at']) {
                    $reservation['canceled_at'] = (new \DateTime("{$row['canceled_at']}", new \DateTimeZone('UTC')))->getTimestamp();
                }
    
                array_push($recent_reservations, $reservation);
            }
    
            return $recent_reservations;
        };
    
        $user['recent_reservations'] = $recent_reservations($this);
        $user['total_price'] = Db::select('SELECT IFNULL(SUM(e.price + s.price), 0) AS `total_price` FROM reservations r INNER JOIN sheets s ON s.id = r.sheet_id INNER JOIN events e ON e.id = r.event_id WHERE r.user_id = ? AND r.canceled_at IS NULL', [$user['id']])[0]['total_price'];
    
        $recent_events = function () use ($user) {
            $recent_events = [];
    
            $rows = Db::select('SELECT event_id FROM reservations WHERE user_id = ? GROUP BY event_id ORDER BY MAX(IFNULL(canceled_at, reserved_at)) DESC LIMIT 5', [$user['id']]);
            foreach ($rows as $row) {
                $event = $this->get_event($row['event_id']);
                foreach (array_keys($event['sheets']) as $rank) {
                    unset($event['sheets'][$rank]['detail']);
                }
                array_push($recent_events, $event);
            }
    
            return $recent_events;
        };
    
        $user['recent_events'] = $recent_events($this);
    
        return $this->response->json($user, 200, JSON_NUMERIC_CHECK);
    }

    /**
     * @RequestMapping(path="/api/actions/login", methods="post")
     * @return Response
     */
    public function login(): Response
    {
        $login_name = $this->request->input('login_name');
        $password = $this->request->input('password');
    
        $user = Db::select('SELECT * FROM users WHERE login_name = ?', [$login_name]);
        $pass_hash = Db::select('SELECT SHA2(?, 256) AS `hash`', [$password])[0]['hash'];
    
        if (!$user || $pass_hash != $user[0]['pass_hash']) {
            return $this->res_error($this->response, 'authentication_failed', 401);
        }
    
        $this->session->set('user_id', (int)$user[0]['id']);
    
        $user = $this->userService->getLoginUser();
    
        return $this->response->json($user, 200, JSON_NUMERIC_CHECK);
    }

    /**
     * @RequestMapping(path="/api/actions/logout", methods="post")
     * @Middleware(LoginRequiredMiddleware::class)
     * @return Response
     */
    public function logout(): Response
    {
        $this->session->clear();

        return $this->response->withStatus(204);
    }

    /**
     * @RequestMapping(path="/api/events", methods="get")
     * @return Response
     */
    public function apiGetEvents(): Response
    {
        $events = array_map(function (array $event) {
            return $this->sanitize_event($event);
        }, $this->get_events());
    
        return $this->response->json($events, 200, JSON_NUMERIC_CHECK);
    }

    /**
     * @RequestMapping(path="/api/events/{id}", methods="get")
     * @param int    $id
     * @return Response
     */
    public function apiGetEventsById(int $id): Response
    {
        $event_id = $id;
        $user = $this->userService->getLoginUser();
        $event = $this->get_event($event_id, $user['id']);
    
        if (empty($event) || !$event['public']) {
            return $this->res_error($this->response, 'not_found', 404);
        }
    
        $event = $this->sanitize_event($event);
    
        return $this->response->json($event, 200, JSON_NUMERIC_CHECK);
    }


    /**
     * @RequestMapping(path="/api/events/{id}/actions/reserve", methods="post")
     * @Middleware(LoginRequiredMiddleware::class)
     * @param int    $id
     * @return Response
     */
    public function apiEventsReserveById(int $id): Response
    {
        $event_id = $id;
        $rank = $this->request->input('sheet_rank');
    
        $user = $this->userService->getLoginUser();
        $event = $this->get_event($event_id, $user['id']);
    
        if (empty($event) || !$event['public']) {
            return $this->res_error($this->response, 'invalid_event', 404);
        }
    
        if (!$this->validate_rank($rank)) {
            return $this->res_error($this->response, 'invalid_rank', 400);
        }
    
        $sheet = null;
        $reservation_id = null;
        while (true) {
            $sheet = Db::select('SELECT * FROM sheets WHERE id NOT IN (SELECT sheet_id FROM reservations WHERE event_id = ? AND canceled_at IS NULL FOR UPDATE) AND `rank` = ? ORDER BY RAND() LIMIT 1', [$event['id'], $rank]);
            if (!$sheet) {
                return $this->res_error($this->response, 'sold_out', 409);
            }
    
            Db::beginTransaction();
            try {
                Db::insert('INSERT INTO reservations (event_id, sheet_id, user_id, reserved_at) VALUES (?, ?, ?, ?)', [$event['id'], $sheet[0]['id'], $user['id'], (new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u')]);
                $result = Db::select('SELECT last_insert_id() AS `id`');
                $reservation_id = $result[0]['id'];
    
                Db::commit();
            } catch (\Exception $e) {
                Db::rollback();
                continue;
            }
    
            break;
        }

        return $this->response->json([
            'id' => $reservation_id,
            'sheet_rank' => $rank,
            'sheet_num' => $sheet[0]['num'],
        ], 202, JSON_NUMERIC_CHECK);
    
    }

    /**
     * @RequestMapping(path="/api/events/{id}/sheets/{ranks}/{num}/reservation", methods="delete")
     * @Middleware(LoginRequiredMiddleware::class)
     * @param int    $id
     * @param string    $ranks
     * @param int    $num
     * @return Response
     */
    public function deletEventByIdRankNum(int $id, string $ranks, int $num): Response
    {
        $event_id = $id;
        $rank = $ranks;
        $num = $num;
    
        $user = $this->userService->getLoginUser();
        $event = $this->get_event($event_id, $user['id']);
    
        if (empty($event) || !$event['public']) {
            return $this->res_error($this->response, 'invalid_event', 404);
        }
    
        if (!$this->validate_rank($rank)) {
            return $this->res_error($this->response, 'invalid_rank', 404);
        }
    
        $sheet = Db::select('SELECT * FROM sheets WHERE `rank` = ? AND num = ?', [$rank, $num]);
        if (!$sheet) {
            return $this->res_error($this->response, 'invalid_sheet', 404);
        }

        $reservation = Db::select('SELECT * FROM reservations WHERE event_id = ? AND sheet_id = ? AND canceled_at IS NULL GROUP BY event_id HAVING reserved_at = MIN(reserved_at) FOR UPDATE', [$event['id'], $sheet[0]['id']]);
        if (!$reservation) {
            // Db::rollback();

            return $this->res_error($this->response, 'not_reserved', 400);
        }

        if ($reservation[0]['user_id'] != $user['id']) {
            // Db::rollback();

            return $this->res_error($this->response, 'not_permitted', 403);
        }

        Db::beginTransaction();
        try {
        
            Db::update('UPDATE reservations SET canceled_at = ? WHERE id = ?', [(new \DateTime('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'), $reservation[0]['id']]);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
    
            return $this->res_error($this->response);
        }
    
        return $this->response->withStatus(204);
    }


    /**
     * @RequestMapping("/admin")
     * @return Response
     */
    public function admin(): Response
    {
        $administrator = $this->userService->getLoginAdministrator();

        $headers = $this->request->getHeaders();
        $events = $this->get_events(function ($event) { return $event; });

        $baseUrl =  ($headers['x-forwarded-proto'][0] ?? 'http' ) . '://' . $headers['host'][0];

        return $this->render->render('admin.twig', [
            'events' => $events,
            'base_url' => $baseUrl,
            'administrator' => $administrator,
        ]);
    }

    /**
     * @RequestMapping(path="/admin/api/actions/login", methods="post")
     * @return Response
     */
    public function adminLogin(): Response
    {
        $login_name = $this->request->input('login_name');
        $password = $this->request->input('password');
    
        $administrator = Db::select('SELECT * FROM administrators WHERE login_name = ?', [$login_name]);
        $pass_hash = Db::select('SELECT SHA2(?, 256) AS `hash`', [$password])[0]['hash'];
    
        if (!$administrator || $pass_hash != $administrator[0]['pass_hash']) {
            return $this->res_error($this->response, 'authentication_failed', 401);
        }
        
        $this->session->set('administrator_id', (int)$administrator[0]['id']);
        
        return $this->response->json($administrator[0], 200, JSON_NUMERIC_CHECK);
    }

    /**
     * @RequestMapping(path="/admin/api/actions/logout", methods="post")
     * @Middleware(AdminLoginRequiredMiddleware::class)
     * @return Response
     */
    public function adminLogout(): Response
    {
        $this->session->clear();

        return $this->response->withStatus(204);
    }

    /**
     * @RequestMapping(path="/admin/api/events", methods="get")
     * @Middleware(AdminLoginRequiredMiddleware::class)
     * @return Response
     */
    public function adminGetEvents(): Response
    {
        $events = $this->get_events(function ($event) { return $event; });
    
        return $this->response->json($events, 200, JSON_NUMERIC_CHECK);
    }

    /**
     * @RequestMapping(path="/admin/api/events", methods="post")
     * @Middleware(AdminLoginRequiredMiddleware::class)
     * @return Response
     */
    public function adminCreateEvents(): Response
    {
        $title = $this->request->input('title');
        $public = $this->request->input('public') ? 1 : 0;
        $price = $this->request->input('price');
    
        $event_id = null;
    
        Db::beginTransaction();
        try {
            Db::insert('INSERT INTO events (title, public_fg, closed_fg, price) VALUES (?, ?, 0, ?)', [$title, $public, $price]);
            $result = Db::select('SELECT last_insert_id() AS `id`');
            $event_id = $result[0]['id'];
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
        }
    
        $event = $this->get_event($event_id);
    
        return $this->response->json($event, 200, JSON_NUMERIC_CHECK);
    }

    /**
     * @RequestMapping(path="/admin/api/events/{id}", methods="get")
     * @Middleware(AdminLoginRequiredMiddleware::class)
     * @param int    $id
     * @return Response
     */
    public function adminGetEventsById(int $id): Response
    {
        $event_id = $id;

        $event = $this->get_event($event_id);
        if (empty($event)) {
            return $this->res_error($this->response, 'not_found', 404);
        }
    
        return $this->response->json($event, 200, JSON_NUMERIC_CHECK);
    }

    /**
     * @RequestMapping(path="/admin/api/events/{id}/actions/edit", methods="post")
     * @Middleware(AdminLoginRequiredMiddleware::class)
     * @param int    $id
     * @return Response
     */
    public function adminEditEventsById(int $id): Response
    {
        $event_id = $id;
        $public = $this->request->input('public') ? 1 : 0;
        $closed = $this->request->input('closed') ? 1 : 0;
    
        if ($closed) {
            $public = 0;
        }
    
        $event = $this->get_event($event_id);
        if (empty($event)) {
            return $this->res_error($this->response, 'not_found', 404);
        }
    
        if ($event['closed']) {
            return $this->res_error($this->response, 'cannot_edit_closed_event', 400);
        } elseif ($event['public'] && $closed) {
            return $this->res_error($this->response, 'cannot_close_public_event', 400);
        }
    
        Db::beginTransaction();
        try {
            Db::update('UPDATE events SET public_fg = ?, closed_fg = ? WHERE id = ?', [$public, $closed, $event['id']]);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
        }
        $event = $this->get_event($event_id);
    
        return $this->response->json($event, 200, JSON_NUMERIC_CHECK);
    }

    /**
     * @RequestMapping(path="/admin/api/reports/events/{id}/sales", methods="get")
     * @param int    $id
     * @return Response
     */
    public function adminGetSalesById(int $id): Response
    {
        $event_id = $id;
        $event = $this->get_event($event_id);
    
        $reports = [];
    
        $reservations = Db::select('SELECT r.*, s.rank AS sheet_rank, s.num AS sheet_num, s.price AS sheet_price, e.price AS event_price FROM reservations r INNER JOIN sheets s ON s.id = r.sheet_id INNER JOIN events e ON e.id = r.event_id WHERE r.event_id = ? ORDER BY reserved_at ASC FOR UPDATE', [$event['id']]);
        foreach ($reservations as $reservation) {
            $report = [
                'reservation_id' => $reservation['id'],
                'event_id' => $reservation['event_id'],
                'rank' => $reservation['sheet_rank'],
                'num' => $reservation['sheet_num'],
                'user_id' => $reservation['user_id'],
                'sold_at' => (new \DateTime("{$reservation['reserved_at']}", new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u').'Z',
                'canceled_at' => $reservation['canceled_at'] ? (new \DateTime("{$reservation['canceled_at']}", new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u').'Z' : '',
                'price' => $reservation['event_price'] + $reservation['sheet_price'],
            ];
    
            array_push($reports, $report);
        }
    
        return $this->render_report_csv($this->response, $reports);
    }

    /**
     * @RequestMapping(path="/admin/api/reports/sales", methods="get")
     * @Middleware(AdminLoginRequiredMiddleware::class)
     * @return Response
     */
    public function adminGetSales(): Response
    {
        $reports = [];
        $reservations = Db::select('SELECT r.*, s.rank AS sheet_rank, s.num AS sheet_num, s.price AS sheet_price, e.id AS event_id, e.price AS event_price FROM reservations r INNER JOIN sheets s ON s.id = r.sheet_id INNER JOIN events e ON e.id = r.event_id ORDER BY reserved_at ASC FOR UPDATE');
        foreach ($reservations as $reservation) {
            $report = [
                'reservation_id' => $reservation['id'],
                'event_id' => $reservation['event_id'],
                'rank' => $reservation['sheet_rank'],
                'num' => $reservation['sheet_num'],
                'user_id' => $reservation['user_id'],
                'sold_at' => (new \DateTime("{$reservation['reserved_at']}", new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u').'Z',
                'canceled_at' => $reservation['canceled_at'] ? (new \DateTime("{$reservation['canceled_at']}", new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u').'Z' : '',
                'price' => $reservation['event_price'] + $reservation['sheet_price'],
            ];
    
            array_push($reports, $report);
        }    
        return $this->render_report_csv($this->response, $reports);
    }

    private function render_report_csv(ResponseInterface $response, array $reports): Response
    {
        usort($reports, function ($a, $b) { return $a['sold_at'] > $b['sold_at']; });
    
        $keys = ['reservation_id', 'event_id', 'rank', 'num', 'price', 'user_id', 'sold_at', 'canceled_at'];
        $body = implode(',', $keys);
        $body .= "\n";
        foreach ($reports as $report) {
            $data = [];
            foreach ($keys as $key) {
                $data[] = $report[$key];
            }
            $body .= implode(',', $data);
            $body .= "\n";
        }

        $tmpfname = tempnam("/tmp", "FOO");
        $handle = fopen($tmpfname, "w");
        fwrite($handle, $body);
        fclose($handle);

        return $response->download($tmpfname, "report.csv");
    }
}

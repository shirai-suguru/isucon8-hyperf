<?php

declare(strict_types=1);

namespace App\Service;

use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;

class UserService
{
    /**
     * @Inject()
     * @var \Hyperf\Contract\SessionInterface
     */
    private $session;

    public function getLoginUser()
    {
        $user_id = $this->session->get('user_id');
        if (null === $user_id) {
            return false;
        }

        $user = Db::select('SELECT id, nickname FROM users WHERE id = ?', [$user_id])[0] ?? null;
        $user['id'] = (int) $user['id'];
        return $user;
    }

    public function getLoginAdministrator()
    {
        $administrator_id = $this->session->get('administrator_id');
        if (null === $administrator_id) {
            return false;
        }

        $administrator = Db::select('SELECT id, nickname FROM administrators WHERE id = ?', [$administrator_id])[0] ?? null;
        $administrator['id'] = (int) $administrator['id'];
        return $administrator;
    }
}
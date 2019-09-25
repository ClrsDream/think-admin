<?php

namespace suframe\thinkAdmin;

use suframe\thinkAdmin\model\AdminMenu;
use suframe\thinkAdmin\model\AdminRoleMenu;
use suframe\thinkAdmin\model\AdminRoleUsers;
use suframe\thinkAdmin\model\AdminUsers;
use suframe\thinkAdmin\traits\SingleInstance;
use think\Collection;
use think\facade\Cache;
use think\facade\Db;

class Auth
{
    use SingleInstance;

    /**
     * @var AdminUsers
     */
    protected $user;

    public function user()
    {
        return $this->user;
    }

    public function setUser($user)
    {
        $this->user = $user;
    }

    /**
     * 登录
     * @param $username
     * @param $password
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \Exception
     */
    public function login($username, $password)
    {
        $user = $this->user->where('username', $username)->find();
        if ($user) {
            throw new \Exception('username error');
        }
        //最大登录失败错误次数
        $max_fail = config('thinkAdmin.auth.max_fail', 10);
        if ($user->login_fail > $max_fail) {
            throw new \Exception('login forbid!');
        }
        $passwordHash = $this->hashPassword($password);
        if ($user->password !== $passwordHash) {
            $user->login_fail += 1;
            $user->save();
            throw new \Exception('password error');
        }
        $token = $this->genToken();
        $user->access_token = $token;
        $user->login_fail = 0;
        $user->save();
        return $token;
    }

    public function logout()
    {
        if (!$this->user) {
            return false;
        }
        $this->user->access_token = null;
        return $this->user->save();
    }

    /**
     * 权限检查
     * @param $http_path
     * @param string $http_method
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function check($http_path, $http_method = 'GET')
    {
        $permission = $this->getUserAllPermission();

        if (!$permission) {
            return false;
        }
        $rs = !$permission->where('http_path', $http_path)
            ->where('http_method', $http_method)->isEmpty();

        if (!$rs) {
            //匹配通配符*
            $likes = $permission
                ->whereLike('http_path', '*')
                ->where('http_method', $http_method)
                ->filter(function ($item) use ($http_path) {
                    $path = str_replace('*', '', $item['http_path']);
                    return strpos($http_path, $path) !== false;
                });
            $rs = !$likes->isEmpty();
        }
        return $rs;
    }

    /**
     * 检测单独权限
     * @param $slug
     * @return bool|Collection
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function checkSlug($slug)
    {
        $permission = $this->getUserAllPermission();
        if (!$permission) {
            return false;
        }
        return !$permission->where('slug', $slug)->isEmpty();
    }

    /**
     * @param $token
     * @return array|bool|Db|\think\Model|null
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function initByToken($token)
    {
        if (!$token) {
            return false;
        }
        $rs = $this->getUsersDb()->where('access_token', $token)->find();
        if (!$rs) {
            return false;
        }
        $user = new AdminUsers($rs);
        $this->setUser($user);
        return $user;
    }

    public function guest()
    {
        return !$this->user();
    }

    /**
     * 获取管理员菜单
     * @return Collection|\think\response\Json
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getAdminMenu()
    {
        $roleIds = AdminRoleUsers::where('user_id', $this->user()->getKey())->column('role_id');
        if (!$roleIds) {
            return json_return([]);
        }
        $menuIds = AdminRoleMenu::where('role_id', 'in', $roleIds)->column('menu_id');
        if (!$menuIds) {
            return json_return([]);
        }
        return AdminMenu::where('id', 'in', $menuIds)
            ->order('order', 'desc')
            ->order('id', 'desc')
            ->select();
    }

    /**
     * @var Collection
     */
    protected $permission;

    /**
     * 管理员的所有权限
     * @return bool|Collection
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    protected function getUserAllPermission()
    {
        if ($this->permission) {
            return $this->permission;
        }
        $user = $this->user();
        if (!$user) {
            return false;
        }
        //缓存
        if(config('thinkAdmin.cache_admin_permission', false)){
            $menu = Cache::get('thinkAdmin.admin.menus');
            if($menu){
                return $this->permission = $menu;
            }
        }

        //用户权限
        $permission_ids = $this->getUserPermissionsDb()->where('user_id', $user->id)->column('permission_id');
        //用户组权限
        $role_ids = $this->getUserRolesDb()->where('user_id', $user->id)->column('role_id');
        if ($role_ids) {
            $permissionRole_ids = $this->getUserRolePermissionsDb()->where('role_id', 'in',
                $role_ids)->column('permission_id');
            if ($permissionRole_ids) {
                $permission_ids = array_merge($permission_ids, $permissionRole_ids);
            }
            //合并权限id
            $permission_ids = array_merge($permission_ids, $permissionRole_ids);
            $permission_ids = array_unique($permission_ids);
        }
        if (!$permission_ids) {
            return false;
        }
        $this->permission = $this->getPermissionsDb()->where('id', 'in', $permission_ids)->select();
        //缓存
        if(config('thinkAdmin.cache_admin_permission', false)) {
            Cache::tag('thinkAdmin')->set('thinkAdmin.admin.menus', $this->permission);
        }
        return $this->permission;
    }

    public function addPermission($permission, $slug = null)
    {
        $slug = $slug ?: md5($permission);
        $this->permission[$slug] = $permission;
    }

    protected function getUsersDb()
    {
        return Db::table(config('thinkAdmin.database.users_table'));
    }

    protected function getUserPermissionsDb()
    {
        return Db::table(config('thinkAdmin.database.user_permissions_table'));
    }

    protected function getUserRolesDb()
    {
        return Db::table(config('thinkAdmin.database.role_users_table'));
    }

    protected function getUserRolePermissionsDb()
    {
        return Db::table(config('thinkAdmin.database.role_permissions_table'));
    }

    protected function getPermissionsDb()
    {
        return Db::table(config('thinkAdmin.database.permissions_table'));
    }

    public function hashPassword($password)
    {
        $hash = config('thinkAdmin.auth.passwordHashFunc');
        if (!$hash) {
            $salt = config('thinkAdmin.auth.passwordSalt', 'thinkAdmin');
            return md5(md5($password . $salt));
        }
        return $hash($password);
    }

    protected function genToken()
    {
        return md5(session_create_id());
    }
}
<?php
namespace App\Services\M;
use Illuminate\Support\Facades\DB;

class MissuUser extends \App\Services\BaseService
{
    protected ?string $connection = 'mysql_master';

    protected string $table = 'missu_users';

    public function login($username, $password): object|bool
    {
        //user_mail，phone，nickname都可以登录
        $fields = [
            "user_uid as id",
            "nickname as name",
            "user_mail",
            "phone",
            "user_psw",
            "user_group_id",
            "user_type",
            "user_id",
            "isSuperAdmin",
        ];
        $fields = implode(",", $fields);
        $row = DB::select("select {$fields} from missu_users where nickname='{$username}' or user_mail='{$username}' or phone='{$username}' ");
        if (!count($row)) return false;
        if (strtoupper($row[0]->user_psw) == strtoupper(md5($password))) {
            return $row[0];
        }
        return false;
    }

    public function getUserInfo($id): object|null
    {
        $res = $this->tb->select('user_uid as id', 'nickname as name')->orWhere('user_uid', $id)->first();
        return $res;
    }

    /*
     * 判断是否是管理员 ，没有用到
     * */
    public function isNotAdmin($id):object|null
    {
        return false;
    }


    public function getPurchasersOrSales(int $sales = 0): array
    {
        $fileds = [
            "user_uid as id",
            "nickname as name",
            "isSuperAdmin",
            "missu_users.user_id",
        ];
        $tb = $this->tb->select($fileds)
            ->join("missu_user_group as g", "g.user_group_id", "missu_users.user_group_id")
            ->where("user_type",2)->where("isSuperAdmin","!=", 1);
        if($sales == 1){
            $tb = $tb->where("g.name","销售");
        }
        if($sales == 3){
            $tb = $tb->where("g.name","采购");
        }

        $data = $tb->get()->toArray();

        //获取1超级管理员
        $admin = $this->tb->select($fileds)->where("isSuperAdmin",1)->where("user_type","2")->get()->toArray();
        return array_merge($data,$admin);
    }

    public function fillUsers($objs, $from, $to = 'user'): void
    {
        $ids = [];
        $missUserMap = [];

        foreach ($objs as $obj) {
            $ids[] = $obj->$from;
        }
        if (!count($ids)) return;
        $ids = array_values(array_unique($ids));
        $temp_ids = array_flip($ids);


        //$miss_users = $this->tb->select("user_uid as id","nickname as name")->whereIn('user_uid', $ids)->get()->toArray();

        //先从user表中查
        $users = DB::table("user")->select('id', 'name')->whereIn('id', $ids)->get()->toArray();
        $userMap = [];
        foreach ($users as $user) {
            $userMap[$user->id] = $user;
            unset($temp_ids[$user->id]); //排除已经查到的，如果有剩余从missu_users表查
        }

        if(count($temp_ids)){
            $miss_users = $this->tb->select(["user_uid as id","nickname as name"])->whereIn('user_uid', array_keys($temp_ids))->get()->toArray();
            foreach ($miss_users as $user) {
                $missUserMap[$user->id] = $user;
            }
        }

        foreach ($objs as $obj) {
            if (isset($userMap[$obj->$from])) {
                $obj->$to = $userMap[$obj->$from];
            }
            else if(isset($missUserMap[$obj->$from])){
                $obj->$to = $missUserMap[$obj->$from];
            }
        }
    }
}
?>

<?php

namespace app\index\service;

use think\Db;
use app\common\exception\JsonErrorException;
use app\common\model\Account;
use app\common\model\account\AccountApplyDetail;
use app\common\model\account\AccountApplyDetailCollection;
use app\common\model\AccountApplyLog;
use app\common\model\AccountLog;
use app\index\service\AccountApplyLog as ServiceAccountApplyLog;
use app\common\model\RoleUser;
use app\common\model\User;
use app\common\service\Common;
use app\common\service\Encryption;
use app\index\validate\AccountApplyValidate;
use app\purchase\service\SupplierService;
use recognition\RecognitionApi;
use app\common\cache\Cache;
use app\common\model\AccountApply;
use app\common\model\account\AccountApplyDetail as ModelAccountApplyDetail;
use app\index\service\Phone as PhoneService;
use app\common\model\Phone;
use app\common\model\Server;
use think\Exception;

/** 基础账号信息
 * Created by PhpStorm.
 * User: min
 * Date: 2017/8/22
 * Time: 18:05
 */
class AccountApplyService
{
    protected $accountApplyModel;
    protected $validate;

    public function __construct()
    {
        if (is_null($this->accountApplyModel)) {
            $this->accountApplyModel = new AccountApply();
        }
        $this->validate = new AccountApplyValidate();
    }

    /**
     * 是否存在
     * @param $params
     * @param string $code
     * @param string $old_id
     * @return bool
     */
    public function isHasCode($params, $code = 'account_name', $old_id = 0)
    {
        if (isset($params[$code]) && $params[$code] != '') {
            $where = [
                $code => $params[$code],
                'channel_id' => $params['channel_id'],
            ];
            $id = $this->accountApplyModel->where($where)->value('id');
            if ($id && $id != $old_id) {
                return true;
            }
        }
        return false;
    }

    /**
     * 是否有权限
     * @return boolean
     */
    public function isHanRole()
    {
        //权限限制
        $userInfo = Common::getUserInfo();
        $role = new Role();
        if ($role->isAdmin($userInfo['user_id'])) {
            return true;
        }
        $users = RoleUser::getUserIds(101); //账号管理组长
        if (in_array($userInfo['user_id'], $users)) {
            return true;
        }
        return false;
    }

    /**
     * 账号列表
     * @param $where
     * @param $page
     * @param $pageSize
     * @param $orderBy
     * @return array
     * @throws \think\Exception
     */
    public function accountList($where, $page = 1, $pageSize = 10, $orderBy = '')
    {
        //权限限制
        $userInfo = Common::getUserInfo();
        if (!$this->isHanRole()) {
            $where['initiate_man'] = $userInfo['user_id'];
        }
        $join[] = ['account_company c', 'c.id = a.company_id', 'left'];
        $field = 'a.id,a.status,a.creator_id,a.account_code,a.channel_id,a.site_code,a.account_name,a.email_id,a.create_time,a.phone_id
        ,c.company,c.phone';
        $count = $this->accountApplyModel->alias('a')->field($field)->where($where)->join($join)->count();
        $accountList = $this->accountApplyModel->alias('a')->field($field)->where($where)->join($join)->order($orderBy)->page($page, $pageSize)->select();
        $allChannelName = Cache::store('channel')->getChannelName(null);
        $server = new BasicAccountService();
        foreach ($accountList as $key => &$value) {
            $user = Cache::store('user')->getOneUser($value['creator_id']);
            $value['creator_id'] = $user['realname'] ?? '';
            $value['status_name'] = AccountApply::STATUS[$value['status']] ?? '';
            $value['channel_id'] = $allChannelName[$value['channel_id']] ?? '';
            $value['account_create_time'] = $value['create_time'];

            $value['phone'] = $server->getPhoneName($value['phone_id']);
            $value['email'] = $server->getEmailName($value['email_id']);
        }
        $result = [
            'data' => $accountList,
            'page' => $page,
            'pageSize' => $pageSize,
            'count' => $count,
        ];
        return $result;
    }

    /**
     * 改变状态信息列表
     * @return array
     */
    public function statusChangeInfo()
    {
        $status = [['value' => '', 'label' => '全部']];

        $statusList = AccountApply::STATUS;
        foreach ($statusList as $key => $name) {
            $status[] = [
                'value' => $key,
                'label' => $name,
            ];
        }
        return $status;
    }

    public function getTimestamp($time)
    {
        return is_numeric($time) ? substr($time, 0, 10) : strtotime($time);
    }

    //保存图片 min
    public function saveImg(&$params)
    {
        if (isset($params['initiate_time']) && $params['initiate_time'] != '') {
            $params['initiate_time'] = $this->getTimestamp($params['initiate_time']);
        }
        if (isset($params['register_time']) && $params['register_time'] != '') {
            $params['register_time'] = $this->getTimestamp($params['register_time']);
        }
        if (isset($params['fulfill_time']) && $params['fulfill_time'] != '') {
            $params['fulfill_time'] = $this->getTimestamp($params['fulfill_time']);
        }
        if (isset($params['account_create_time']) && $params['account_create_time'] != '') {
            $params['account_create_time'] = $this->getTimestamp($params['account_create_time']);
        }
        if (isset($params['company_time']) && $params['company_time'] != '') {
            $params['company_time'] = $this->getTimestamp($params['company_time']);
        }
        $suplierService = new SupplierService();
        // 保存图片 营业执照
        if (isset($params['charter_url']) && $params['charter_url'] != '' && (strpos($params['charter_url'], 'data:image') !== false)) {
            $fileResult = $suplierService->base64DecImg($params['charter_url'], 'upload/baseaccount/' . date('Y-m-d'), time() . rand(0, 100));
            $params['charter_url'] = $fileResult['filePath'];
        } else {
            unset($params['charter_url']);
        }
        // 保存图片 身份证正面
        if (isset($params['corporation_id_front']) && $params['corporation_id_front'] != '' && (strpos($params['corporation_id_front'], 'data:image') !== false)) {

            $fileResult = $suplierService->base64DecImg($params['corporation_id_front'], 'upload/baseaccount/' . date('Y-m-d'), time() . rand(0, 100));
            $params['corporation_id_front'] = $fileResult['filePath'];
        } else {
            unset($params['corporation_id_front']);
        }
        // 保存图片 身份证反面
        if (isset($params['corporation_id_contrary']) && $params['corporation_id_contrary'] != '' && (strpos($params['corporation_id_contrary'], 'data:image') !== false)) {
            $fileResult = $suplierService->base64DecImg($params['corporation_id_contrary'], 'upload/baseaccount/' . date('Y-m-d'), time() . rand(0, 100));
            $params['corporation_id_contrary'] = $fileResult['filePath'];
        } else {
            unset($params['corporation_id_contrary']);
        }
        if (isset($params['open_licence']) && $params['open_licence'] != '' && (strpos($params['open_licence'], 'data:image') !== false)) {
            $fileResult = $suplierService->base64DecImg($params['open_licence'], 'upload/baseaccount/' . date('Y-m-d'), time() . rand(0, 100));
            $params['open_licence'] = $fileResult['filePath'];
        } else {
            unset($params['open_licence']);
        }
        if (!isset($params['vat_data'])) {
            $params['vat_data'] = json_encode([]);
        }
        if (!isset($params['vat_attachment'])) {
            $params['vat_attachment'] = json_encode([]);
        }
    }

    /**
     * @title 储存图片，并将图片路劲转化成二维数组
     * @param $params
     * @return array
     */
    public function saveCompanyImages($params)
    {
        $images = [
            'charter_url' => [],
            'corporation_id_front' => [],
            'corporation_id_contrary' => [],
            'open_licence' => [],
        ];

        $suplierService = new SupplierService();

        // 保存图片 营业执照
        if (!empty($params['charter_url'])) {

            $charterArr = json_decode($params['charter_url'], true);
            if (!is_array($charterArr)) {
                throw new JsonErrorException('参数错误');
            }

            foreach ($charterArr as $key => $item) {
                if (!isset($item['path']) || !isset($item['is_add'])) {
                    throw new JsonErrorException('参数错误');
                }

                if (strpos($item['path'], 'data:image') !== false && $item['is_add'] == true) {
                    $imagesArr = $suplierService->base64DecImg($item['path'], 'upload/baseaccount/' . date('Y-m-d'), time() . rand(0, 100));
                    $imagesArr['fileName'] = $item['name'];
                    $images['charter_url'][] = $imagesArr;

                } else if (strpos($item['path'], 'data:application/pdf') !== false && $item['is_add'] == true) {
                    $imagesArr = $this->base64DecPdf($item['path'], 'upload/baseaccount/' . date('Y-m-d'), time() . rand(0, 100));
                    $imagesArr['fileName'] = $item['name'];
                    $images['charter_url'][] = $imagesArr;

                } else {
                    $imagesArr['fileName'] = $item['name'];
                    $imagesArr['filePath'] = $item['path'];
                    $images['charter_url'][] = $imagesArr;
                }
            }

        }

        // 保存图片 身份证正面
        if (!empty($params['corporation_id_front'])) {

            $corporationArr = json_decode($params['corporation_id_front'], true);
            if (!is_array($corporationArr)) {
                throw new JsonErrorException('参数错误');
            }

            foreach ($corporationArr as $key => $item) {
                if (!isset($item['path']) || !isset($item['is_add'])) {
                    throw new JsonErrorException('参数错误');
                }

                if (strpos($item['path'], 'data:image') !== false && $item['is_add'] == true) {

                    $imagesArr = $suplierService->base64DecImg($item['path'], 'upload/baseaccount/' . date('Y-m-d'), time() . rand(0, 100));
                    $imagesArr['fileName'] = $item['name'];
                    $images['corporation_id_front'][] = $imagesArr;

                } else if (strpos($item['path'], 'data:application/pdf') !== false && $item['is_add'] == true) {

                    $imagesArr = $this->base64DecPdf($item['path'], 'upload/baseaccount/' . date('Y-m-d'), time() . rand(0, 100));
                    $imagesArr['fileName'] = $item['name'];
                    $images['corporation_id_front'][] = $imagesArr;

                } else {
                    $imagesArr['fileName'] = $item['name'];
                    $imagesArr['filePath'] = $item['path'];
                    $images['corporation_id_front'][] = $imagesArr;
                }
            }

        }

        // 保存图片 身份证反面
        if (!empty($params['corporation_id_contrary'])) {

            $contraryArr = json_decode($params['corporation_id_contrary'], true);
            if (!is_array($contraryArr)) {
                throw new JsonErrorException('参数错误');
            }

            foreach ($contraryArr as $key => $item) {
                if (!isset($item['path']) || !isset($item['is_add'])) {
                    throw new JsonErrorException('参数错误');
                }

                if (strpos($item['path'], 'data:image') !== false && $item['is_add'] == true) {
                    $imagesArr = $suplierService->base64DecImg($item['path'], 'upload/baseaccount/' . date('Y-m-d'), time() . rand(0, 100));
                    $imagesArr['fileName'] = $item['name'];
                    $images['corporation_id_contrary'][] = $imagesArr;

                } else if (strpos($item['path'], 'data:application/pdf') !== false && $item['is_add'] == true) {
                    $imagesArr = $this->base64DecPdf($item['path'], 'upload/baseaccount/' . date('Y-m-d'), time() . rand(0, 100));
                    $imagesArr['fileName'] = $item['name'];
                    $images['corporation_id_contrary'][] = $imagesArr;

                } else {
                    $imagesArr['fileName'] = $item['name'];
                    $imagesArr['filePath'] = $item['path'];
                    $images['corporation_id_contrary'][] = $imagesArr;
                }
            }

        }

        // 保存图片 开户许可证
        if (!empty($params['open_licence'])) {

            $openLicenceArr = json_decode($params['open_licence'], true);
            if (!is_array($openLicenceArr)) {
                throw new JsonErrorException('参数错误');
            }

            foreach ($openLicenceArr as $key => $item) {

                if (!isset($item['path']) || !isset($item['is_add'])) {
                    throw new JsonErrorException('参数错误');
                }

                if (strpos($item['path'], 'data:image') !== false && $item['is_add'] == true) {

                    $imagesArr = $suplierService->base64DecImg($item['path'], 'upload/baseaccount/' . date('Y-m-d'), time() . rand(0, 100));
                    $imagesArr['fileName'] = $item['name'];
                    $images['open_licence'][] = $imagesArr;

                } else if (strpos($item['path'], 'data:application/pdf') !== false && $item['is_add'] == true) {

                    $imagesArr = $this->base64DecPdf($item['path'], 'upload/baseaccount/' . date('Y-m-d'), time() . rand(0, 100));
                    $imagesArr['fileName'] = $item['name'];
                    $images['open_licence'][] = $imagesArr;

                } else {
                    $imagesArr['fileName'] = $item['name'];
                    $imagesArr['filePath'] = $item['path'];
                    $images['open_licence'][] = $imagesArr;
                }
            }

        }

        return $images;
    }

    /**
     * 反编译data/base64数据流并创建文件
     * @author Lonny ciwdream@gmail.com
     * @param string $baseData data/base64数据流
     * @param string $Dir 存放文件目录
     * @param string $fileName 文件名称(不含文件后缀)
     * @return mixed 返回新创建文件路径或布尔类型
     */
    function base64DecPdf($baseData, $Dir, $fileName)
    {
        $base_path = ROOT_PATH . '/public/';
        $imgPath = $base_path . '/' . $Dir;
        try {
            if (!is_dir($imgPath) && !mkdir($imgPath, 0777, true)) {
                return false;
            }
            $expData = explode(';', $baseData);
            $postfix = explode('/', $expData[0]);

            if (strstr($postfix[0], 'application')) {
                $storageDir = $imgPath . '/' . $fileName . '.' . $postfix[1];

                $export = base64_decode(str_replace("{$expData[0]};base64,", '', $baseData));

                try {
                    file_put_contents($storageDir, $export);
                    return [
                        'fileName' => $fileName,
                        'filePath' => $Dir . '/' . $fileName . '.' . $postfix[1]
                    ];

                } catch (Exception $e) {
                    return false;
                }
            } else {
                return false;
            }
        } catch (Exception $e) {
            return false;
        }
        return false;
    }


    public function saveBase($data, $userInfo)
    {
        $flag = $this->validate->scene('save_base')->check($data);
        if ($flag == false) {
            throw new Exception($this->validate->getError());
        }
        $data['creator_id'] = $userInfo['user_id'];
        $data['create_time'] = time();
        $data['status'] = AccountApply::STATUS_REGISTER;
        if (!empty($data['company_id'])) {
            $AccountCompanyService = new AccountCompanyService();
            $AccountCompanyService->checkCompany($data['company_id']);
        }
        if (!empty($data['phone_id'])) {
            $phoneService = new PhoneService();
            $phoneService->checkPhone($data['phone_id']);
        }
        if (!empty($data['server_id'])) {
            $ManagerServer = new ManagerServer();
            $ManagerServer->checkServer($data['server_id']);
        }
        $this->checkSite($data['channel_id'], $data['company_id']);
        //$this->checkCanCompany($data);
        Db::startTrans();
        try {
            $Model = new AccountApply();
            $ServiceAccountApplyLog = new ServiceAccountApplyLog();
            $Model->isUpdate(false)->allowField(true)->save($data);
            $ServiceAccountApplyLog->add()->save($Model->id, $userInfo['user_id'], $userInfo['realname']);
            Db::commit();
            return ['message' => '保存成功', 'data' => $this->read($Model->id)];
        } catch (Exception $ex) {
            Db::rollback();
            throw $ex;
        }
    }

    public function updateBase($id, $param, $userInfo)
    {
        $accountApplyModel = new AccountApply();
        $old = $accountApplyModel->where(['id' => $id])->find();
        if (empty($old)) {
            throw new Exception('账号不存在');
        }
        $oldData = $old->toArray();
        if (!in_array($old['status'], [
            AccountApply::STATUS_REGISTER,
            AccountApply::STATUS_AUDIT,
            AccountApply::STATUS_REGISTER_OK
        ])) {
            throw new Exception('当前状态为' . $old->status_txt . ",不能编辑");
        }
        $this->checkCanUpdateCompany($old, $param);
        $param['updater_id'] = $userInfo['user_id'];
        $param['update_time'] = time();
        $ServiceAccountApplyLog = new ServiceAccountApplyLog();
        Db::startTrans();
        try {
            $old->allowField(true)->save($param);
            $ServiceAccountApplyLog->mdf('帐号注册资料', $oldData, $param)->save($old->id, $userInfo['user_id'], $userInfo['realname']);
            Db::commit();
            return ['message' => '保存成功', 'data' => $this->read($id)];
        } catch (Exception $ex) {
            Db::rollback();
            throw $ex;
        }

    }

    /**
     * @title 判断能否更新手机公司邮箱
     * @param $param
     * @param $old
     * @author starzhan <397041849@qq.com>
     */
    public function checkCanUpdateCompany($old, $param)
    {
        if (isset($param['company_id'])) {
            if ($old['company_id'] != $param['company_id']) {
                $ModelAccount = new Account();
                $count = $ModelAccount->where('channel_id', $old['channel_id'])
                    ->where('company_id', $old['company_id'])
                    ->count();
                if ($count > 0) {
                    throw new Exception('当前公司不能修改');
                }
                $ModelAccountApply = new AccountApply();
                $count = $ModelAccountApply
                    ->where('channel_id', $old['channel_id'])
                    ->where('company_id', $old['company_id'])
                    ->where('id', '<>', $old['id'])
                    ->count();
                if ($count > 0) {
                    throw new Exception('当前公司不能修改');
                }

                $ModelAccount = new Account();
                $count = $ModelAccount->where('channel_id', $old['channel_id'])
                    ->where('company_id', $param['company_id'])
                    ->count();
                if ($count > 0) {
                    throw new Exception('目标公司已被使用');
                }
                $ModelAccountApply = new AccountApply();
                $count = $ModelAccountApply
                    ->where('channel_id', $old['channel_id'])
                    ->where('company_id', $param['company_id'])
                    ->where('id', '<>', $old['id'])
                    ->count();
                if ($count > 0) {
                    throw new Exception('目标公司已被使用');
                }
            }
        }
        if (isset($param['phone_id'])) {
            if ($old['phone_id'] != $param['phone_id']) {
                $ModelAccount = new Account();
                $count = $ModelAccount->where('channel_id', $old['channel_id'])
                    ->where('company_id', $old['company_id'])
                    ->count();
                if ($count > 0) {
                    throw new Exception('当前手机号不能修改');
                }
                $ModelAccountApply = new AccountApply();
                $count = $ModelAccountApply
                    ->where('channel_id', $old['channel_id'])
                    ->where('company_id', $old['company_id'])
                    ->where('id', '<>', $old['id'])
                    ->count();
                if ($count > 0) {
                    throw new Exception('当前手机号不能修改');
                }
                $ModelAccount = new Account();
                $count = $ModelAccount->where('channel_id', $old['channel_id'])
                    ->where('phone_id', $param['phone_id'])
                    ->count();
                if ($count > 0) {
                    throw new Exception('目标手机已被使用');
                }
                $ModelAccountApply = new AccountApply();
                $count = $ModelAccountApply
                    ->where('channel_id', $old['channel_id'])
                    ->where('phone_id', $param['phone_id'])
                    ->where('id', '<>', $old['id'])
                    ->count();
                if ($count > 0) {
                    throw new Exception('目标手机已被使用');
                }
            }

        }
        if (isset($param['server_id'])) {
            if ($old['server_id'] && $old['server_id'] != $param['server_id']) {
                if (empty($param['server_id'])) {
                    throw new Exception('目标服务器不能为空');
                }
                $ModelAccount = new Account();
                $count = $ModelAccount->where('channel_id', $old['channel_id'])
                    ->where('company_id', $old['company_id'])
                    ->count();
                if ($count > 0) {
                    throw new Exception('当前服务器不能修改');
                }
                $ModelAccountApply = new AccountApply();
                $count = $ModelAccountApply
                    ->where('channel_id', $old['channel_id'])
                    ->where('company_id', $old['company_id'])
                    ->where('id', '<>', $old['id'])
                    ->count();
                if ($count > 0) {
                    throw new Exception('当前服务器不能修改');
                }

                $ModelAccount = new Account();
                $count = $ModelAccount->where('channel_id', $old['channel_id'])
                    ->where('phone_id', $param['server_id'])
                    ->count();
                if ($count > 0) {
                    throw new Exception('目标服务器已被使用');
                }
                $ModelAccountApply = new AccountApply();
                $count = $ModelAccountApply
                    ->where('channel_id', $old['channel_id'])
                    ->where('phone_id', $param['server_id'])
                    ->where('id', '<>', $old['id'])
                    ->count();
                if ($count > 0) {
                    throw new Exception('目标服务器已被使用');
                }
            }
        }
    }

    /**
     * @title 只能再选完站点的时候判断
     * @param $param
     * @throws Exception
     * @author starzhan <397041849@qq.com>
     */
    public function checkCanCompany($param)
    {

        if (isset($param['company_id'])) {
            $ModelAccount = new Account();
            $count = $ModelAccount->where('channel_id', $param['channel_id'])
                ->where('company_id', $param['company_id'])
                ->count();
            if ($count > 0) {
                throw new Exception('当前公司不能注册');
            }
            $ModelAccountApply = new AccountApply();
            $count = $ModelAccountApply
                ->where('channel_id', $param['channel_id'])
                ->where('company_id', $param['channel_id'])
                ->count();
            if ($count > 0) {
                throw new Exception('当前公司不能修改');
            }
        }
    }

    public function checkSite($channel_id, $company_id)
    {
        $edSite = $this->getEdSite($channel_id, $company_id);
        $ModelAccountApply = new AccountApply();
        $allSite = $allSite = $ModelAccountApply->getAllSite($channel_id);
        $diff = array_diff($allSite, $edSite);
        if (empty($diff)) {
            throw new Exception('该公司名称/服务器/手机号已注册账号，不可重复选择');
        }
    }

    public function checkThisSite($channel_id, $company_id, $site, $id = 0)
    {
        $edSite = $this->getEdSite($channel_id, $company_id, $id);
        $siteArr = explode(',', $site);
        $ed = array_intersect($edSite, $siteArr);
        if ($ed) {
            throw new Exception('站点' . implode(',', $ed) . "已注册，无需重新注册");
        }
    }

    public function register($id, $param, $userInfo)
    {
        $accountApplyModel = new AccountApply();
        $old = $accountApplyModel->where(['id' => $id])->find();
        if (empty($old)) {
            throw new Exception('账号不存在');
        }
        $codes = [
            'submit_register',
            'save'
        ];
        if (empty($param['submit_code'])) {
            throw new Exception('提交码不能为空');
        }
        if (!in_array($param['submit_code'], $codes)) {
            throw new Exception('当前提交码不存在');
        }
        $oldAccounts = $this->getOldAccounts($id);
        $oldKeys = array_keys($oldAccounts);
        $EmailService = new Email();
        $insert = [];
        $mdf = [];
        $delKeys = [];
        if (isset($param['accounts'])) {
            $accounts = json_decode($param['accounts'], true);
            if (empty($accounts)) {
                throw new Exception('提交的数据不能为空');
            }
            foreach ($accounts as $account) {
                if (empty($account['id'])) {
                    $insert[] = $account;
                    continue;
                }
                if (in_array($account['id'], $oldKeys)) {
                    $mdf[$account['id']] = $account;
                }
            }
            $delKeys = array_diff($oldKeys, array_keys($mdf));
        }
        $ServiceAccountApplyLog = new ServiceAccountApplyLog();
        Db::startTrans();
        try {
            foreach ($insert as $info) {
                if (empty($info['account_name'])) {
                    throw new Exception('帐号全称为必填项');
                }
                $info = array_filter($info);
                if (empty($info)) {
                    continue;
                }
                $detailModel = new AccountApplyDetail();
                $info['creator_id'] = $userInfo['user_id'];
                $info['create_time'] = time();
                $info['account_apply_id'] = $id;
                if (isset($info['email_id']) && $info['email_id']) {
                    $EmailService->checkEmail($info['email_id'], $old['channel_id']);
                }
                if (isset($info['site_code']) && $info['site_code']) {
                    $this->checkThisSite($old['channel_id'], $old['company_id'], $info['site_code']);
                }
                if (isset($info['account_name']) && $info['account_name']) {
                    $this->checkAccountName($info['account_name'], $old['channel_id']);
                }
                if (isset($info['account_code']) && $info['account_code']) {
                    $this->checkAccountCode($info['account_code']);
                }
                $detailModel->allowField(true)->isUpdate(false)->save($info);
                $ServiceAccountApplyLog->addDetail($info['account_name']);
                $detailId = $detailModel->id;
                if (!empty($info['collection_data'])) {
                    foreach ($info['collection_data'] as $collectionInfo) {
                        $collectionInfo = array_filter($collectionInfo);
                        if (empty($collectionInfo)) {
                            continue;
                        }
                        $collectionInfo['creator_id'] = $userInfo['user_id'];
                        $collectionInfo['create_time'] = $info['create_time'];
                        $collectionInfo['account_apply_detail_id'] = $detailId;
                        $collectionInfo['account_apply_id'] = $id;
                        $ModelAccountApplyDetailCollection = new AccountApplyDetailCollection();
                        $ModelAccountApplyDetailCollection
                            ->allowField(true)
                            ->isUpdate(false)
                            ->save($collectionInfo);
                        $ServiceAccountApplyLog->addCollection($collectionInfo['collection_account'] ?? '空');
                    }
                }
            }
            if ($delKeys) {
                AccountApplyDetailCollection::where('account_apply_detail_id', 'in', $delKeys)->delete();
                AccountApplyDetail::where('id', 'in', $delKeys)->delete();
                foreach ($delKeys as $delId) {
                    $oldDetail = $oldAccounts[$delId];
                    $ServiceAccountApplyLog->delDetail($oldDetail['account_name']);
                }
            }
            if ($mdf) {
                foreach ($mdf as $updateId => $updateData) {
                    unset($updateData['id']);
                    $oldDetail = $oldAccounts[$updateId];
                    $detailModel = new AccountApplyDetail();
                    if (isset($updateData['email_id']) && $updateData['email_id']) {
                        if ($oldDetail['email_id'] != $updateData['email_id']) {
                            $EmailService->checkEmail($oldDetail['email_id'], $old['channel_id'], 0, $updateId);
                        }
                    }
                    if (isset($updateData['site_code']) && $updateData['site_code']) {
                        if ($oldDetail['site_code'] != $updateData['site_code']) {
                            $this->checkThisSite($old['channel_id'], $old['company_id'], $updateData['site_code']);
                        }
                    }
                    if (isset($updateData['account_name']) && $updateData['account_name']) {
                        if ($oldDetail['account_name'] != $updateData['account_name']) {
                            $this->checkAccountName($updateData['account_name'], $old['channel_id'], $oldDetail['id']);
                        }
                    }
                    if (isset($updateData['account_code']) && $updateData['account_code']) {
                        if ($oldDetail['account_code'] != $updateData['account_code']) {
                            $this->checkAccountCode($updateData['account_code'], $oldDetail['id']);
                        }
                    }
                    $updateData['update_time'] = time();
                    $updateData['updater_id'] = $userInfo['user_id'];
                    $detailModel->allowField(true)->save($updateData, ['id' => $updateId]);
                    $ServiceAccountApplyLog->mdfDetail($oldDetail['account_name'], $oldDetail, $updateData);
                    if (!empty($updateData['collection_data'])) {
                        $oldCollections = $this->getOldCollection($updateId);
                        $oldKeys = array_keys($oldCollections);
                        $insert = [];
                        $mdf = [];
                        foreach ($updateData['collection_data'] as $collection) {
                            if (empty($collection['id'])) {
                                $insert[] = $collection;
                                continue;
                            }
                            if (in_array($collection['id'], $oldKeys)) {
                                $mdf[$collection['id']] = $collection;
                            }
                        }
                        $delKeys = array_diff($oldKeys, array_keys($mdf));
                        foreach ($insert as $collectionInfo) {
                            $collectionInfo = array_filter($collectionInfo);
                            if (empty($collectionInfo)) {
                                continue;
                            }
                            $collectionInfo['creator_id'] = $userInfo['user_id'];
                            $collectionInfo['create_time'] = time();
                            $collectionInfo['account_apply_detail_id'] = $updateId;
                            $collectionInfo['account_apply_id'] = $id;
                            $ModelAccountApplyDetailCollection = new AccountApplyDetailCollection();
                            $ModelAccountApplyDetailCollection
                                ->allowField(true)
                                ->isUpdate(false)
                                ->save($collectionInfo);
                            $code = empty($collectionInfo['collection_account']) ? '空' : $collectionInfo['collection_account'];
                            $ServiceAccountApplyLog->addCollection($code);
                        }
                        if ($delKeys) {
                            AccountApplyDetailCollection::where('id', 'in', $delKeys)->delete();
                            foreach ($delKeys as $delCollectionId) {
                                $detailCollection = $oldCollections[$delCollectionId];
                                $ServiceAccountApplyLog->delCollection($detailCollection['collection_account']);
                            }
                        }
                        foreach ($mdf as $collectionInfoId => $updateCollection) {
                            unset($updateCollection['id']);
                            $detailCollection = $oldCollections[$collectionInfoId];
                            $updateCollection['updater_id'] = $userInfo['user_id'];
                            $updateCollection['update_time'] = time();
                            $ModelAccountApplyDetailCollection = new AccountApplyDetailCollection();
                            $ModelAccountApplyDetailCollection->allowField(true)->save($updateCollection, ['id' => $collectionInfoId]);
                            $ServiceAccountApplyLog->mdfCollection($detailCollection['collection_account'], $detailCollection, $updateCollection);
                        }
                    }
                }
            }
            switch ($param['submit_code']) {
                case 'submit_register':
                    $old['status'] = AccountApply::STATUS_AUDIT;
                    $old['update_time'] = time();
                    $old['updater_id'] = $userInfo['user_id'];
                    $old->save();
                    $ServiceAccountApplyLog->submitRegister();
                    break;
                case 'save':
                    $ServiceAccountApplyLog->saveRegister();
                    break;
            }
            $ServiceAccountApplyLog->save($old->id, $userInfo['user_id'], $userInfo['realname']);
            Db::commit();
            return ['message' => '保存成功', 'data' => $this->getRegister($id)];
        } catch (Exception $ex) {
            Db::rollback();
            throw $ex;
        }

    }

    public function checkAccountName($account_name, $channel_id, $id = 0)
    {
        if ($account_name) {
            $AccountApplyDetail = new AccountApplyDetail();
            $count = $AccountApplyDetail->alias('d')
                ->join('account_apply a', 'a.id=d.account_apply_id', 'left')
                ->where('d.id', '<>', $id)
                ->where('a.channel_id', $channel_id)
                ->where('d.account_name', $account_name)
                ->count();
            if ($count > 0) {
                throw new Exception("帐号全称[{$account_name}]已存在!");
            }
        }
    }

    public function checkAccountCode($account_code, $id = 0)
    {
        if ($account_code) {
            $count = AccountApplyDetail::where('account_code', $account_code)
                ->where('id', '<>', $id)
                ->count();
            if ($count > 0) {
                throw new Exception("帐号简称[{$account_code}]已存在!");
            }
            $ModelAccount = new Account();
            $count = $ModelAccount->where('account_code', $account_code)->count();
            if ($count > 0) {
                throw new Exception("帐号简称[{$account_code}]已存在!");
            }
        }
    }

    public function viewPassword($id, $password)
    {
        $user = Common::getUserInfo();
        if (empty($user)) {
            throw new Exception('非法操作');
        }
        $model = new AccountApplyDetail();
        $userModel = new User();
        $userInfo = $userModel->where(['id' => $user['user_id']])->find();
        if (empty($userInfo)) {
            throw new Exception('外来物种入侵', 500);
        }
        if ($userInfo['password'] != User::getHashPassword($password, $userInfo['salt'])) {
            throw new Exception('登录密码错误', 500);
        }
        //查看邮箱号信息
        $emailInfo = $model->field('password')->where(['id' => $id])->find();
        if (empty($emailInfo)) {
            throw new Exception('账号记录不存在', 500);
        }
        return ['password' => $emailInfo['password']];
    }

    /**
     * @title 获取注册信息
     * @param $id
     * @author starzhan <397041849@qq.com>
     */
    public function getRegister($id)
    {
        $accountApplyModel = new AccountApply();
        $old = $accountApplyModel->where(['id' => $id])->find();
        if (empty($old)) {
            throw new Exception('账号不存在');
        }
        $result = [];
        $oldAccounts = $this->getOldAccounts($id);
        foreach ($oldAccounts as $account) {
            $row = [];
            $row['account_name'] = $account['account_name'];
            $row['id'] = $account['id'];
            $row['credit_card_id'] = $account['credit_card_id'];
            $row['credit_card_txt'] = $account['credit_card_txt'];
            $row['account_code'] = $account['account_code'];
            $row['site_code'] = $account['site_code'];
            $row['email_id'] = $account['email_id'];
            $row['email_txt'] = $account['email_txt'];
            $row['shop_name'] = $account['shop_name'];
            $row['password'] = $account['password'];
            $row['credit_card_id'] = $account['credit_card_id'];
            $row['collection_data'] = [];
            $oldCollections = $this->getOldCollection($account['id']);
            foreach ($oldCollections as $collection) {
                $r = [];
                $r['collection_account'] = $collection['collection_account'];
                $r['collection_type'] = $collection['collection_type'];
                $r['collection_email'] = $collection['collection_email'];
                $r['collection_name'] = $collection['collection_name'];
                $row['collection_data'][] = $r;
            }
            $result[] = $row;
        }
        return ['accounts' => $result];
    }


    public function getOldAccounts($id)
    {
        $result = [];
        $ModelAccountApplyDetail = new ModelAccountApplyDetail();
        $tmp = $ModelAccountApplyDetail->where('account_apply_id', $id)->select();
        foreach ($tmp as $v) {
            $result[$v['id']] = $v;
        }
        return $result;
    }

    public function getOldCollection($account_apply_detail_id)
    {
        $result = [];
        $ModelAccountApplyDetailCollection = new AccountApplyDetailCollection();
        $tmp = $ModelAccountApplyDetailCollection->where('account_apply_detail_id', $account_apply_detail_id)->select();
        foreach ($tmp as $v) {
            $result[$v['id']] = $v;
        }
        return $result;
    }


    public function updateAudit($id, $param, $userInfo)
    {
        unset($param['id']);
        $accountApplyModel = new AccountApply();
        $old = $accountApplyModel->where(['id' => $id])->find();
        if (empty($old)) {
            throw new Exception('账号不存在');
        }
        $param['updater_id'] = $userInfo['user_id'];
        $param['update_time'] = time();
        $codes = [
            'submit_audit',
            'submit_push',
            'save'
        ];
        if (empty($param['submit_code'])) {
            throw new Exception('提交码不能为空');
        }
        if (!in_array($param['submit_code'], $codes)) {
            throw new Exception('当前提交码不存在');
        }
        if ($old['channel_id'] == 2) {
            $validate = new AccountApplyValidate();
            $flag = $validate->scene('save_audit_2')->check($param);
            if ($flag === false) {
                throw new Exception($validate->getError());
            }
        }
        $is_push = false;
        $ServiceAccountApplyLog = new ServiceAccountApplyLog();
        $remark = !empty($param['remark']) ? $param['remark'] : $old['remark'];
        Db::startTrans();
        try {
            switch ($param['submit_code']) {
                case 'submit_audit':
                    $param['status'] = AccountApply::STATUS_REGISTER_OK;
                    $ServiceAccountApplyLog->submitAudit($remark);
                    break;
                case 'submit_push':
                    $param['status'] = AccountApply::STATUS_REGISTER_OK_WITHOUT_KYC;
                    $ServiceAccountApplyLog->submitAndPush($remark);
                    $is_push = true;
                    break;
            }
            $old->allowField(true)->save($param);
            if ($is_push) {
                $this->accountPushIn($id);
            }
            $ServiceAccountApplyLog->save($old->id, $userInfo['user_id'], $userInfo['realname']);
            Db::commit();
            return ['message' => '保存成功', 'data' => $this->getAudit($id)];
        } catch (Exception $ex) {
            Db::rollback();
            throw $ex;
        }
    }

    public function getAudit($id)
    {

        $accountApplyModel = new AccountApply();
        $old = $accountApplyModel->where(['id' => $id])
            ->field('id,audit_data,is_kyc,main_shop,shop_category,remark')
            ->find();
        if (empty($old)) {
            throw new Exception('账号不存在');
        }
        return $this->row($old);

    }

    public function updateResult($id, $param, $userInfo)
    {
        unset($param['id']);
        $accountApplyModel = new AccountApply();
        $old = $accountApplyModel->where(['id' => $id])->find();
        if (empty($old)) {
            throw new Exception('账号不存在');
        }
        $codes = [
            'submit_push',
            'sure',
            'cancel'
        ];
        if (empty($param['submit_code'])) {
            throw new Exception('提交码不能为空');
        }
        if (!in_array($param['submit_code'], $codes)) {
            throw new Exception('当前提交码不存在');
        }
        $is_push = false;
        $param['updater_id'] = $userInfo['user_id'];
        $param['update_time'] = $userInfo['user_id'];
        $ServiceAccountApplyLog = new ServiceAccountApplyLog();
        switch ($param['submit_code']) {
            case 'submit_push':
                $validate = new AccountApplyValidate();
                $flag = $validate->scene('save_result')->check($param);
                if ($flag === false) {
                    throw new Exception($validate->getError());
                }
                $param['status'] = AccountApply::STATUS_PUSH;
                $ServiceAccountApplyLog->successPush();
                $is_push = true;
                break;
            case 'sure':
                $param['status'] = AccountApply::STATUS_PUSH;
                $ServiceAccountApplyLog->success();
                break;
            case 'cancel':
                $param['status'] = AccountApply::STATUS_INVALID;
                if (empty($param['reason'])) {
                    throw new Exception('作废原因不能为空');
                }
                $ServiceAccountApplyLog->cancel($param['reason']);
                break;
        }
        Db::startTrans();
        try {
            $old->allowField(true)->save($param);
            if ($is_push) {
                $this->accountPushIn($id);
            }
            $ServiceAccountApplyLog->save($old->id, $userInfo['user_id'], $userInfo['realname']);
            Db::commit();
            return ['message' => '保存成功', 'data' => $this->getResult($id)];
        } catch (Exception $ex) {
            Db::rollback();
            throw $ex;
        }
    }

    public function getResult($id)
    {
        $accountApplyModel = new AccountApply();
        $old = $accountApplyModel->where(['id' => $id])
            ->field('id,fulfill_time,reg_result,audit_data')
            ->find();
        if (empty($old)) {
            throw new Exception('账号不存在');
        }
        return $this->row($old);
    }

    /** 保存账号信息
     * @param $data
     * @return array
     */
    public function save($data)
    {
        if (!$this->validate->check($data)) {
            throw new JsonErrorException($this->validate->getError(), 500);
        }
        if ($this->isHasCode($data, 'account_name')) {
            throw new JsonErrorException('账号全称已经存在', 500);
        }
        if ($this->isHasCode($data, 'account_code')) {
            throw new JsonErrorException('账号账号简称已经存在', 500);
        }

        if (!empty($data['phone_id'])) {
            $BasicAccountService = new BasicAccountService();
            $BasicAccountService->checkPhone($data['phone_id'], $data['channel_id']);
        }
        if (!empty($data['email_id'])) {
            $Email = new Email();
            $Email->checkEmail($data['email_id']);
        }

        $data['create_time'] = time();
        $data['update_time'] = time();
        $userInfo = Common::getUserInfo();
        $data['creator_id'] = $data['updater_id'] = $userInfo['user_id'] ?? 0;
        $this->saveImg($data);
        //启动事务
        Db::startTrans();
        try {

            $this->accountApplyModel->isUpdate(false)->save($data);
            //获取最新的数据返回
            $new_id = $this->accountApplyModel->id;
            //添加操作日志
            AccountApplyLog::addLog($new_id, AccountApplyLog::add, $data);
            if (!empty($data['phone_id'])) {
                $PhoneService = new PhoneService();
                $flag = $PhoneService->bind($data['phone_id']);
                if (!$flag) {
                    throw new Exception('手机号绑定失败，检查是否被使用');
                }
            }
            if (!empty($data['email_id'])) {
                $Email = new Email();
                $flag = $Email->bind($data['email_id']);
                if (!$flag) {
                    throw new Exception('邮箱号绑定失败，检查是否被使用');
                }
            }
            Db::commit();
            return $this->read($new_id);
        } catch (\Exception $e) {
            Db::rollback();
            throw new JsonErrorException($e->getMessage(), 500);
        }
    }

    /** 账号信息
     * @param $id
     * @return array|false|\PDOStatement|string|\think\Model
     */
    public function read($id)
    {
        $accountInfo = $this->accountApplyModel->where(['id' => $id])->find();
        if (empty($accountInfo)) {
            throw new JsonErrorException('账号不存在', 500);
        }
        return $this->row($accountInfo);
    }

    public function row($row, $order_field = false)
    {
        $result = [];
        $v = $row->toArray();
        isset($v['id']) && $result['id'] = $row['id'];
        if (isset($v['channel_id'])) {
            $result['channel_id'] = $row['channel_id'];
            $result['channel_txt'] = $row['channel_txt'];
        }
        if (isset($v['phone_id'])) {
            $result['phone_id'] = $row['phone_id'];
            $result['phone'] = $row->phone ? $row->phone->phone : '';
        }
        isset($v['create_time']) && $result['create_time'] = $row['create_time'];
        if (isset($v['company_id'])) {
            $AccountCompany = new AccountCompanyService();
            $prefix = $AccountCompany->getUrlPrefix();
            $result['company_id'] = $row['company_id'];
            $result['company_name'] = '';
            $result['company_type'] = '';
            $result['charter_url'] = '';
            $result['company_registration_number'] = '';
            $result['company_address_zip'] = '';
            $result['corporation'] = '';
            $result['corporation_identification'] = '';
            $result['corporation_id_front'] = '';
            $result['corporation_id_contrary'] = '';
            $result['corporation_address_zip'] = '';
            if ($row->companyRelation) {
                $result['company_name'] = $row->companyRelation->company;
                $result['company_type'] = $row->companyRelation->type;
                if ($row->companyRelation->charter_url) {
                    $result['charter_url'] = $prefix . "/" . $row->companyRelation->charter_url;
                }
                $result['company_registration_number'] = $row->companyRelation->company_registration_number;
                $result['company_address_zip'] = $row->companyRelation->company_address_zip;
                $result['corporation'] = $row->companyRelation->corporation;
                $result['corporation_identification'] = $row->companyRelation->corporation_identification;
                if ($row->companyRelation->corporation_id_front) {
                    $result['corporation_id_front'] = $prefix . "/" . $row->companyRelation->corporation_id_front;
                }
                if ($row->companyRelation->corporation_id_contrary) {
                    $result['corporation_id_contrary'] = $prefix . "/" . $row->companyRelation->corporation_id_contrary;
                }
                $result['corporation_address_zip'] = $row->companyRelation->corporation_address_zip;
            }
        }
        if (isset($v['server_id'])) {
            $result['server_id'] = $row['server_id'];
            $result['server_txt'] = $row['server_txt'];
        }
        if (isset($v['register_id'])) {
            $result['register_id'] = $row['register_id'];
            $result['register_txt'] = $row['register_txt'];
        }
        isset($v['register_ip']) && $result['register_ip'] = $row['register_ip'];
        isset($v['audit_data']) && $result['audit_data'] = $row['audit_data'];
        isset($v['is_kyc']) && $result['is_kyc'] = $row['is_kyc'];
        isset($v['main_shop']) && $result['main_shop'] = $row['main_shop'];
        isset($v['shop_category']) && $result['shop_category'] = $row['shop_category'];
        isset($v['remark']) && $result['remark'] = $row['remark'];

        isset($v['fulfill_time']) && $result['fulfill_time'] = $row['fulfill_time'];
        isset($v['reg_result']) && $result['reg_result'] = $row['reg_result'];
        isset($v['status']) && $result['status'] = $row['status'];
        isset($v['status']) && $result['status_txt'] = $row['status_txt'];

        if ($order_field) {
            $detail = $row->detail;
            $site_code = [];
            $email = [];
            $account_name = [];
            $account_code = [];
            foreach ($detail as $v) {
                $site_code[] = $v['site_code'];
                $account_name[] = $v['account_name'];
                $account_code[] = $v['account_code'];
                if ($v->email) {
                    $email[] = $v->email->email;
                }
            }
            $result['email'] = implode(',', $email);
            $result['site_code'] = implode(',', $site_code);
            $result['account_name'] = implode(',', $account_name);
            $result['account_code'] = implode(',', $account_code);
        }
        return $result;
    }


    public function rows($ret, $showDetail = false)
    {
        $result = [];
        foreach ($ret as $v) {
            $row = $this->row($v, $showDetail);
            $result[] = $row;
        }
        return $result;

    }


    /** 更新
     * @param $id
     * @param $data
     * @return mixed
     */
    public function update($id, $data)
    {


    }


    private function accountPushIn($id)
    {
        $data = (new AccountApply())->where('id', $id)->find();
        if (!$data) {
            throw new Exception('帐号信息不存在无法推送');
        }
        $add = [
            'channel_id' => $data['channel_id'],
            'site_code' => '',
            'account_name' => '',
            'password' => '',
            'account_code' => '',
            'server_id' => $data['server_id'],
            'email_password' => '',
            'company_id' => $data['company_id'],
            'account_creator' => $data['register_id'],
            'collection_msg' => '[]',
            'credit_card_id' => 0,
            'email_id' => 0,
            'phone_id' => $data['phone_id'],
            'account_create_time' => time()
        ];

        $detail = $data->detail;
        if ($detail) {
            foreach ($detail as $detailInfo) {
                $infoData = $add;
                $infoData['site_code'] = $detailInfo['site_code'];
                $infoData['account_name'] = $detailInfo['account_name'];
                $infoData['password'] = $detailInfo['password'];
                $infoData['shop_name'] = $detailInfo['shop_name'];
                $infoData['account_code'] = $detailInfo['account_code'];
                $infoData['email_id'] = $detailInfo['email_id'];
                $infoData['credit_card_id'] = $detailInfo['credit_card_id'];
                $collection_msg = [];
                $tmpCollection = $detailInfo->collection;
                if ($tmpCollection) {
                    foreach ($tmpCollection as $collectionInfo) {
                        $infoCollection = [];
                        $infoCollection['collection_type'] = $collectionInfo['collection_type'];
                        $infoCollection['collection_user'] = $collectionInfo['collection_name'];
                        $infoCollection['collection_email'] = $collectionInfo['collection_email'];
                        $infoCollection['collection_account'] = $collectionInfo['collection_account'];
                        $collection_msg[] = $infoCollection;
                    }
                }
                $infoData['collection_msg'] = json_encode($collection_msg);
                $model = (new Account());
                $isHasCode = $model->where('account_code', $infoData['account_code'])->value('account_code');
                if ($isHasCode) {
                    throw new JsonErrorException('该简称已经被使用', 400);
                }
                $model->allowField(true)->isUpdate(false)->save($infoData);
            }

        } else {
            throw new Exception('推送失败，信息不全无法推送');
        }

    }


    /**
     * 状态
     * @param $ids
     * @param $data
     * @param $type
     * @return bool
     */
    public function status($ids, $data, $type)
    {
        //启动事务
        Db::startTrans();
        try {
            switch ($type) {
                case 'update':
                    $data['update_time'] = time();
                    $this->accountApplyModel->where('id', 'in', $ids)->update($data);
                    foreach ($ids as $k => $id) {
                        $oldData = $this->accountApplyModel->where('id', $id)->find();
                        //添加操作日志
                        AccountApplyLog::addLog($id, AccountApplyLog::update, $data, $oldData);
                    }
                    break;
            }
            Db::commit();
            return true;
        } catch (\Exception $e) {
            Db::rollback();
            throw new JsonErrorException($e->getMessage() . $e->getFile() . $e->getLine(), 400);
        }
    }


    /**
     * 服务器已绑定的平台账号
     * @param $channel_id
     * @param $server_id
     * @return false|\PDOStatement|string|\think\Collection
     */
    public function alreadyBind($channel_id, $server_id)
    {
        $where['channel_id'] = ['eq', $channel_id];
        $where['server_id'] = ['eq', $server_id];
        $accountList = $this->accountApplyModel->field('account_code')->where($where)->select();
        return $accountList;
    }

    /**
     * 查看账号资料操作日志
     * @param $account_id
     * @return false|\PDOStatement|string|\think\Collection
     */
    public function log($account_id)
    {
        $logList = (new AccountLog())->field(true)->where(['account_id' => $account_id])->order('create_time desc')->select();
        return $logList;
    }


    public $automaticType = [
        'IDCard' => 1, //身份证
        'BusinessLicense' => 2, // 营业执照
    ];

    /**
     * 识别图片  min
     * @param $image
     * @param $type
     * @param $request
     * @return array
     */
    public function automatic($image, $type, $request)
    {
        $res = [
            'success' => false,
            'message' => '',
            'data' => []
        ];
        $apiData = AutomaticSetService::getAccount();
        if (!$apiData) {
            $res['message'] = '暂无可用识别账号！';
            return $res;
        }
        if (!$apiData['class'] || !$apiData['app_id'] || !$apiData['app_key'] || !$apiData['app_secret_key'] || !$apiData['execute_class'] || !$apiData['func']) {
            $res['message'] = "账号【" . $apiData['name'] . "】信息不全";
            return $res;
        }

        $pos = strpos($image, $request->domain());
        if ($pos !== false) {
            $pos += strlen($request->domain());
            $image = substr($image, $pos);
        }

        $api = RecognitionApi::instance()->loader($apiData['class']);
        $api->setApi($apiData['app_id'], $apiData['app_key'], $apiData['app_secret_key'], $apiData['execute_class']);
        $re = $api->advanced($image, $apiData['func']);
        if ($re['success'] == false) {
            $res['message'] = $re['error'];
            return $res;
        }
        $res['success'] = true;
        switch ($type) {
            case $this->automaticType['IDCard']:
                $res['data'] = [
                    'type' => 'IDCard',
                    'name' => $this->getImageValue($re['data'], '姓名'),
                    'sex' => $this->getImageValue($re['data'], '性别', 3),
                    'IDCard' => $this->getImageValue($re['data'], '身份号码'),
                ];
                break;
            case $this->automaticType['BusinessLicense']:
                $res['data'] = [
                    'type' => 'BusinessLicense',
                    'USCC' => $this->getImageValue($re['data'], '信用代码'),
                    'Company' => $this->getImageValue($re['data'], '称'),
                    'CompanyType' => $this->getImageValue($re['data'], '主体类型'),
                    'Address' => $this->getImageValue($re['data'], '所') . $this->getImageValue($re['data'], '', '', 7),
                    'LawPerson' => $this->getImageValue($re['data'], '法定代表人'),
                    'careateTime' => $this->getImageValue($re['data'], '', '', 10),
                ];
                break;
            default:
        }
        return $res;
    }

    public function getImageValue($data, $name = '', $num = null, $index = false)
    {
        $list = $data['words_result'];
        if ($index) {
            return $list[$index]['words'];
        }
        foreach ($list as $key => $v) {
            $value = $v['words'];
            $pos = strpos($value, $name);
            if ($pos !== false) {
                $pos += strlen($name);
                if ($num) {
                    return substr($value, $pos, $num);
                }
                return substr($value, $pos);
            }
        }
    }

    public function export()
    {
        $encryption = new Encryption();
        $where['account_code'] = ['not in', ['58wishyang', '231wishzog', '358wishwag', '357wishwan', '361wishwag', '234wishgu']];
        $dataList = $this->accountApplyModel->field('account_name,account_code,password,channel_id')->where(['channel_id' => 3])->where('status', '<>', 5)->where($where)->select();
        foreach ($dataList as $k => &$value) {
            $value = $value->toArray();
            $value['account_name'] = trim($value['account_name']);
            $value['account_code'] = trim($value['account_code']);
            switch ($value['channel_id']) {
                case 1:
                    $value['channel_id'] = 'ebay';
                    break;
                case 2:
                    $value['channel_id'] = 'amazon';
                    break;
                case 3:
                    $value['channel_id'] = 'wish';
                    break;
                case 4:
                    $value['channel_id'] = 'aliExpress';
                    break;
            }
            $value['password'] = $encryption->decrypt($value['password']);
        }
        //return $dataList;
        $this->export_csv($dataList);
    }

    function export_csv($data)
    {
        $string = "";
        foreach ($data as $key => $value) {
            foreach ($value as $k => $val) {
                $value[$k] = iconv('utf-8', 'GB2312//IGNORE', $value[$k]);
            }
            $string .= implode(",", $value) . "\n"; //用英文逗号分开
        }
        $filename = date('Ymd') . '.csv'; //设置文件名
        header("Content-type:text/csv");
        header("Content-Disposition:attachment;filename=" . $filename);
        header('Cache-Control:must-revalidate,post-check=0,pre-check=0');
        header('Expires:0');
        header('Pragma:public');
        echo $string;
    }

    public function getLog($id)
    {
        return AccountApplyLog::getLog($id);
    }

    public function getEdSite($channel_id, $company_id, $detailId = 0, $accountId = 0)
    {
        $ModelAccount = new Account();
        $edSite = [];
        $accounts = $ModelAccount->where('company_id', $company_id)
            ->where('channel_id', $channel_id)
            ->where('id', '<>', $accountId)
            ->field('site_code,company_id,server_id,phone_id')
            ->select();
        foreach ($accounts as $accountInfo) {
            $temp = explode(',', $accountInfo['site_code']);
            $edSite = array_merge($edSite, $temp);
        }
        $ModelAccountApplyDetail = new AccountApplyDetail();
        $applies = $ModelAccountApplyDetail->alias('d')
            ->join('account_apply a', 'a.id=d.account_apply_id', 'left')
            ->field('a.phone_id,a.server_id,a.company_id,d.site_code')
            ->where('a.company_id', $company_id)
            ->where('d.id', '<>', $detailId)
            ->where('a.channel_id', $channel_id)
            ->select();
        foreach ($applies as $accountInfo) {
            $temp = explode(',', $accountInfo['site_code']);
            $edSite = array_merge($edSite, $temp);
        }
        $edSite = array_unique($edSite);
        return $edSite;
    }

    public function relateInfo($company_id, $channel_id)
    {
        $ModelAccount = new Account();
        $edSite = [];
        $info = [];
        $accounts = $ModelAccount->where('company_id', $company_id)
            ->where('channel_id', $channel_id)
            ->field('site_code,company_id,server_id,phone_id')
            ->select();
        foreach ($accounts as $accountInfo) {
            $temp = explode(',', $accountInfo['site_code']);
            $edSite = array_merge($edSite, $temp);
        }
        if ($accounts) {
            $accountInfo = $accounts[0];
            $info['company_id'] = $accountInfo['company_id'];
            $info['phone_id'] = $accountInfo['phone_id'];
            $info['server_id'] = $accountInfo['server_id'];
            $info['server'] = $accountInfo->server ? $accountInfo->server->name : '';
            $info['company'] = $accountInfo->company ? $accountInfo->company->company : '';
            $info['phone'] = $accountInfo->phone ? $accountInfo->phone->phone : '';

        }
        $ModelAccountApply = new AccountApply();
        $ModelAccountApplyDetail = new AccountApplyDetail();
        $applies = $ModelAccountApplyDetail->alias('d')
            ->join('account_apply a', 'a.id=d.account_apply_id', 'left')
            ->field('a.phone_id,a.server_id,a.company_id,d.site_code,d.account_apply_id')
            ->where('a.company_id', $company_id)
            ->where('a.channel_id', $channel_id)
            ->select();
        foreach ($applies as $accountInfo) {
            $temp = explode(',', $accountInfo['site_code']);
            $edSite = array_merge($edSite, $temp);
        }
        if (!$info && $applies) {
            $appliesInfo = $applies[0];
            $info['company_id'] = $appliesInfo['company_id'];
            $info['phone_id'] = $appliesInfo['phone_id'];
            $info['server_id'] = $appliesInfo['server_id'];
            $info['server'] = $appliesInfo->server;
            $info['company'] = $appliesInfo->company;
            $info['phone'] = $appliesInfo->phone;
        }

        if (!$info) {
            $info['company_id'] = $company_id;
            $phoneInfo = $this->getPhoneId($channel_id);
            $info['phone_id'] = $phoneInfo['id'];
            $info['phone'] = $phoneInfo['phone'];
            $serverInfo = $this->getServerId($channel_id);
            $info['server_id'] = $serverInfo['id'];
            $info['server'] = $serverInfo['name'];
            $info['status'] = 0;
        } else {
            $info['status'] = 1;
        }

        $info['map'] = [];
        if ($channel_id == 2) {
            $map = AccountApply::AMAZON_SITE;
            foreach ($map as $vv) {
                $info['map'][] = explode(',', $vv['data']);
            }
        }
        $allSite = $ModelAccountApply->getAllSite($channel_id);
        $result = [];
        foreach ($allSite as $v) {
            $row = [];
            $row['value'] = $v;
            $row['label'] = $v;
            if (in_array($v, $edSite)) {
                $row['status'] = 1;
            } else {
                $row['status'] = 0;
            }
            $result[] = $row;
        }
        $info['can_use_site'] = $result;
        return $info;

    }

    public function getPhoneId($channel_id)
    {
        $ModelAccount = new Account();
        $aPhone = $ModelAccount->field('phone_id')
            ->where('channel_id', $channel_id)
            ->group('phone_id');
        $ModelAccountApply = new AccountApply();
        $aPhone1 = $ModelAccountApply->field('phone_id')
            ->where('channel_id', $channel_id)
            ->group('phone_id');
        $edPhoneId = [];
        foreach ($aPhone as $phone) {
            $edPhoneId[] = $phone['phone_id'];
        }
        foreach ($aPhone1 as $phone) {
            $edPhoneId[] = $phone['phone_id'];
        }
        $ModelPhone = new Phone();
        $ModelPhone = $ModelPhone->field('id,phone')->where('status', Phone::STATUS_ENABLE);
        if ($edPhoneId) {
            $ModelPhone = $ModelPhone->where('id', 'not in', $edPhoneId);
        }
        $info = $ModelPhone->find();
        if (!$info) {
            return false;
        }
        return $info;

    }

    public function getServerId($channel_id)
    {
        $ModelAccount = new Account();
        $aServer = $ModelAccount->field('server_id')
            ->where('channel_id', $channel_id)
            ->group('server_id');
        $ModelAccountApply = new AccountApply();
        $aServer1 = $ModelAccountApply->field('server_id')
            ->where('channel_id', $channel_id)
            ->group('server_id');
        $edServerId = [];
        foreach ($aServer as $server) {
            $edServerId[] = $server['server_id'];
        }
        foreach ($aServer1 as $server) {
            $edServerId[] = $server['server_id'];
        }
        $ModelServer = new Server();
        $ModelServer = $ModelServer->field('id,name')->where('status', 0);
        if ($edServerId) {
            $ModelServer = $ModelServer->where('id', 'not in', $edServerId);
        }
        $info = $ModelServer->find();
        if (!$info) {
            return false;
        }
        return $info;
    }

    public function getWhere($param)
    {
        $ModelAccountApply = new AccountApply();
        $ModelAccountApply = $ModelAccountApply->alias('a');
        if (isset($param['status']) && $param['status'] !== '') {
            $ModelAccountApply = $ModelAccountApply->where('a.status', $param['status']);
        }
        if (isset($param['channel_id']) && $param['channel_id']) {
            $ModelAccountApply = $ModelAccountApply->where('a.channel_id', $param['channel_id']);
        }
        $is_join = false;
        if (isset($param['site_code']) && $param['site_code']) {
            if ($is_join == false) {
                $ModelAccountApply = $ModelAccountApply->join('account_apply_detail d', 'd.account_apply_id=a.id');
                $is_join = true;
            }
            $ModelAccountApply = $ModelAccountApply->where('d.site_code', 'like', "%{$param['site_code']}%");
        }
        if (isset($param['snType']) && $param['snType'] && !empty($param['snText'])) {
            if ($is_join == false) {
                $ModelAccountApply = $ModelAccountApply->join('account_apply_detail d', 'd.account_apply_id=a.id');
                $is_join = true;
            }
            switch ($param['snType']) {
                case 'name':
                    $ModelAccountApply = $ModelAccountApply
                        ->where('d.account_name', 'like', "%{$param['snText']}%");
                    break;
                case 'code':
                    $ModelAccountApply = $ModelAccountApply
                        ->where('d.account_code', 'like', "%{$param['snText']}%");
                    break;
            }

        }
        if (isset($param['register_id']) && $param['register_id'] !== '') {
            $ModelAccountApply = $ModelAccountApply->where('a.register_id', $param['register_id']);
        }
        if (isset($param['create_time_st']) && $param['create_time_st'] !== '') {
            $st = strtotime($param['create_time_st']);
            $ModelAccountApply = $ModelAccountApply->where('a.create_time', '>=', $st);
        }
        if (isset($param['create_time_nd']) && $param['create_time_nd'] !== '') {
            $nd = strtotime($param['create_time_nd'] . " 23:59:59");
            $ModelAccountApply = $ModelAccountApply->where('a.create_time', '<=', $nd);
        }
        return $ModelAccountApply;
    }

    private function getSort($param)
    {
        if (!empty($param['sort_field']) && !empty($param['sort_type'])) {
            return $param['sort_field'] . " " . $param['sort_type'];
        } else {
            return 'a.id desc';
        }
    }

    public function index($page, $pageSize, $param)
    {
        $result = ['data' => []];
        $result['page'] = $page;
        $result['pageSize'] = $pageSize;
        $result['count'] = $this->getWhere($param)->count();
        if ($result['count'] == 0) {
            return $result;
        }
        $o = $this->getWhere($param);
        $sort = $this->getSort($param);
        $ret = $o->page($page, $pageSize)
            ->field("a.id,a.channel_id,a.phone_id,a.company_id,a.status,a.register_id,a.create_time,a.status")
            ->order($sort)->select();
        if ($ret) {
            $result['data'] = $this->rows($ret, 1);
        }
        return $result;
    }

}
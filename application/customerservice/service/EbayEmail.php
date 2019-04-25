<?php
// +----------------------------------------------------------------------
// | 邮件service
// +----------------------------------------------------------------------
// | File  : Email.php
// +----------------------------------------------------------------------
// | Author: LiuLianSen <3024046831@qq.com>
// +----------------------------------------------------------------------
// | Date  : 2017-07-19
// +----------------------------------------------------------------------

namespace app\customerservice\service;


use app\common\model\ebay\EbayEmailContent;
use app\common\model\Order;
use app\common\model\OrderAddress;
use app\common\model\User;
use swoole\TaskRunner;
use think\Db;
use erp\AbsServer;
use app\common\cache\Cache;
use app\common\model\customerservice\EmailAccounts;
use app\common\model\ebay\EbayOrder;
use app\common\service\ChannelAccountConst;
use app\common\service\Common;
use app\customerservice\validate\EbayEmailValidate;
use app\order\service\OrderService;
use imap\EmailAccount;
use imap\MailSender;
use imap\MailReceiver;
use think\Exception;
use think\Log;
use think\Validate;
use app\common\model\ebay\EbayEmail as EbayEmailList;
use app\common\traits\User as UserTraits;
use app\common\model\ChannelUserAccountMap;
use app\common\model\ebay\EbayAccount;
use app\common\exception\JsonErrorException;
use app\common\model\Account as accountModel;
use app\common\model\EmailServer as EmailServiceMode;
use app\common\service\Encryption;
use app\internalletter\service\InternalLetterService;
use app\common\model\Postoffice as ModelPostoffice;

class EbayEmail
{
    use UserTraits;

    public $channel_id = 0;
    private $uid = 0;
    public $encryption;

    /**
     * 是否是测试发送
     * true,将对TEST_SEND_RECEIVER设置的收件箱进行发送
     * @var bool
     */
    const IS_TEST_SEND = false;

    /**
     * 测试发送时的收件箱
     * @var  string
     */
    const TEST_SEND_RECEIVER = '2654126245@qq.com';

    protected $sendMailAttachRoot = ROOT_PATH .'public/upload/email/ebay';

    public static $tplFields = [
        '${buyerId}',
        '${buyerName}',
        '${platformOrderNumber}',
        '${shipper}',
        '${trackingNumber}'
    ];

    protected $mailSendError = null;
    protected $ebayEmailModel;
    protected $ebayEmailContentModel;

    public function __construct()
    {
        $this->encryption = new Encryption();
        $this->channel_id = ChannelAccountConst::channel_ebay;
        if (is_null($this->ebayEmailModel)) {
            $this->ebayEmailModel = new EbayEmailList();
        }
        if (is_null($this->ebayEmailContentModel)) {
            $this->ebayEmailContentModel = new EbayEmailContent();
        }
    }


    /**
     * @param $prarms
     * @return array
     */
    public function getPageInfo(&$prarms)
    {
        $page = isset($prarms['page']) ? intval($prarms['page']) : 1;
        !$page && $page = 1;
        $pageSize = isset($prarms['pageSize']) ? intval($prarms['pageSize']) : 50;
        !$pageSize && $pageSize = 50;
        return [$page, $pageSize];
    }


    /**
     * @param $params
     * @return array
     */
    protected function getEmailCondition($params)
    {
        $where = [];

        if (isset($params['platform']) && in_array($params['platform'], ['1', '2'])) {
            $where['platform'] = $params['platform'];
//            if(strtolower($platform)=='1'){
//                $params['channel_id'] = ChannelAccountConst::channel_ebay;
//            }else{
//                $params['channel_id'] = ChannelAccountConst::channel_Paypal;
//            }
        }
        if (isset($params['is_read']) && $params['is_read']!='') {
            $where['is_read'] = $params['is_read'];
        }

        if (isset($params['account_id']) && $params['account_id']!='') {
            $where['account_id'] = $params['account_id'];
        }
        if (isset($params['infringement']) && is_numeric($params['infringement'])) {
            $where['infringement'] = $params['infringement'];
        }
        if (isset($params['type']) && is_numeric($params['type'])) {
            $where['type'] = $params['type'];
        }
        //根据帐号ID找到邮箱；
//        if (!empty($params['account_id'])) {
//            $receiver = EmailAccounts::where(['channel_id' => $params['channel_id'], 'account_id' => $params['account_id']])->value('email_account');
//            if(empty($receiver)) {
//                $where['id'] = 0;
//            } else {
//                $where['receiver'] = $receiver;
//            }
//        }
        if (isset($params['receiver_id']) && $params['receiver_id']!='') {
            $where['receiver_id'] = $params['receiver_id'];
        }

        $b_time = !empty(param($params, 'start_date')) ? $params['start_date'] . ' 00:00:00' : '';
        $e_time = !empty(param($params, 'end_date')) ? $params['end_date'] . ' 23:59:59' : '';

        if ($b_time) {
            if (Validate::dateFormat($b_time, 'Y-m-d H:i:s')) {
                $b_time = strtotime($b_time);
            } else {
                throw new Exception('起始日期格式错误(格式如:2017-01-01)', 400);
            }
        }

        if ($e_time) {
            if (Validate::dateFormat($e_time, 'Y-m-d H:i:s')) {
                $e_time = strtotime($e_time);
            } else {
                throw new Exception('截止日期格式错误(格式如:2017-01-01)', 400);
            }
        }

        if ($b_time && $e_time) {
            $where['sync_time'] = ['BETWEEN', [$b_time, $e_time]];
        } elseif ($b_time) {
            $where['sync_time'] = ['EGT', $b_time];
        } elseif ($e_time) {
            $where['sync_time'] = ['ELT', $e_time];
        }

        return $where;
    }


    /**
     * 更新接收邮件的查看、是否需要回复、标志状态
     * @param $id
     * @param $params
     * @return bool
     * @throws Exception
     */
    public function updateReceivedMail($id, $params)
    {
        $record = EmailList::get($id);
        if (!$record) {
            throw new Exception('邮件id不存在', 404);
        }

        $isRead = isset($params['is_read']) ? $params['is_read'] : null;
        if (!is_null($isRead)) {
            if (Validate::in($isRead, '0,1')) {
                $record->is_read = $isRead;
            } else {
                throw new Exception('is_read值不合法', 400);
            }
        }

        $isReplied = isset($params['is_replied']) ? $params['is_replied'] : null;
        if (!is_null($isReplied)) {
            if ($isReplied == 2) {
                $record->is_replied = $isReplied;
            } else {
                throw new Exception('is_replied状态值不合法', 400);
            }
        }

        $flagId = isset($params['flag_id']) ? $params['flag_id'] : null;
        if (!is_null($flagId)) {
            $flags = Db::table('email_flags')->column('id');
            if (in_array($flagId, $flags)) {
                $record->flag_id = $flagId;
            } else {
                throw new Exception('flag_id值不合法', 400);
            }
        }
        try {
            $record->isUpdate()->save();
            return true;
        } catch (\Exception $ex) {
            Log::error($ex->getTraceAsString());
            throw new Exception('程序内部错误', 500);
        }
    }

    /**
     * @param $params
     * @return array
     * @throws Exception
     */
    protected function getSendMailInfo($params)
    {
        $validate = new EbayEmailValidate();

        if (!$validate->scene('send_without_order')->check($params)) {
            throw new Exception($validate->getError(), 400);
        }

        $ebayAccount = EbayAccount::where(['id' => $params['account_id']])->find();
        if (!$ebayAccount) {
            throw new Exception('对应的Ebay平台帐号未找到', 404);
        }

//        $accountInfo = EmailAccounts::where(['channel_id' => $params['channel_id'], 'account_id' => $params['account_id']])->find();

        $accountInfo = accountModel::where(['a.channel_id' => ChannelAccountConst::channel_ebay, 'a.account_code' => $ebayAccount['code']])->alias('a')
            ->join('email e','e.id=a.email_id','LEFT')
            ->field('e.email, e.password, e.post_id, e.status, e.is_send')->find();

        if (empty($accountInfo)) {
            throw new Exception('该账号未设置邮件功能', 404);
        } elseif ($accountInfo['status'] == 0 || $accountInfo['is_send'] == 0) {
            throw new Exception('该平台账号不具有发送邮件的权限', 401);
        }

        $accountInfo['email_password'] = $this->encryption->decrypt($accountInfo['password']); //密码解密

        $emailService = ModelPostoffice::where([ 'id' => $accountInfo['post_id'] ])->find();

        $info = [
//            'account_id' => $ebayAccount['id'],
            'account_code' => $ebayAccount['code'],
            'account_name' => $ebayAccount['account_name'],
            'account_id' => $params['account_id'],

            'creator_id' => $params['customer_id'],
            'email_account_id' => $params['account_id'],
            'email_account' => $accountInfo['email'],
            'email_password' => $accountInfo['email_password'],

            'imap_url' => $emailService['imap_url'],
            'imap_ssl_port' => $emailService['imap_port'],
            'smtp_url' => $emailService['smtp_url'],
            'smtp_ssl_port' => $emailService['smtp_port'],

            'buyer_id' => $params['buyer_name'],
            'buyer_name' => '',
            'buyer_email' => $params['buyer_email'],

            'channel_order_number' => '',
            'shipping_id' => '',
            'shipping_name' => '',
            'shipping_number' => '',

            'subject' => $params['subject'],
            'content' => $params['content'],
        ];
        return $info;
    }


    /**
     * @param $params
     * @return array|bool
     * @throws Exception
     */
    public function send($params)
    {
        $return = [
            //status 0 一般错误，1发送成功，2并发超三个，3每分钟超30条，4每天超10000条，5 3或者4
            'status'=>0,
            'message'=>'',
        ];

        $platform= '';
        if (isset($params['platform']) && in_array($params['platform'], ['1', '2'])) {
            $platform = $params['platform'];
        }

        $info = $this->getSendMailInfo($params);
        $info['type'] = 2;
        $info['platform'] = $platform;

        //替换模板内容
        $replace = [
            $info['buyer_id'],
            $info['buyer_name'],
            $info['channel_order_number'],
            $info['shipping_name'],
            $info['shipping_number']
        ];
        $content = str_replace(static::$tplFields, $replace, $info['content']);

        //接收附近
        $attachment = $this->getUploadAttachment($params, $this->sendMailAttachRoot, $info['email_account']);
        $info['attachment'] = [];
        if (!empty($attachment)) {
            $info['attachment'][] = str_replace(ROOT_PATH, '', $attachment);
        }

        //创建发送记录
        $recordId = $this->addSendRecord($info);

        //进行邮件发送
        $account = new EmailAccount(
            $info['account_id'],
            $info['email_account'],
            $info['email_password'],
            $info['imap_url'],
            $info['imap_ssl_port'],
            $info['smtp_url'],
            $info['smtp_ssl_port'],
            ($platform==1)?'ebay':'paypal'
        );
        $return = $this->sendMail($account, $info['buyer_email'], $recordId, $info['subject'], $content, $attachment);
//        if ($return['isSent']) {
//            return true;
//        } else {
//            throw new Exception($this->mailSendError, 400);
//        }
        return $return;
    }

    /**
     * @param EmailAccount $account
     * @param $customerEmail
     * @param $sentId
     * @param $subject
     * @param $content
     * @param v
     * @return bool
     * @throws Exception
     */
    public function sendMail(EmailAccount $account, $customerEmail, $sentId, $subject, $content, $attachFile)
    {
        try{
            $return = [
                //status 0 一般错误，1发送成功，2并发超三个，3每分钟超30条，4每天超10000条，5 3或者4
                'status'=>0,
                'message'=>'',
            ];

            $sender = MailSender::getInstance();
            $sender->setAccount($account);
            if (self::IS_TEST_SEND) { //测试发送
                $customerEmail = self::TEST_SEND_RECEIVER;
            }
            $return = $sender->send($customerEmail, $subject, $content, $attachFile);
            $email = $this->ebayEmailModel::where(['id' => $sentId])->find();
            if ($return['status']==1) {
                $email->status = 1;                 //发送成功
                $email->sync_time = time();
            } else {
                $email->status = 2;                 //发送失败
                $this->mailSendError = $sender->getLastErrorInfo();
            }
            $email->isUpdate()->save();
            return $return;
        } catch (Exception $e){
            throw new Exception($e->getMessage());
        }
    }


    /**
     * 回复邮件
     * @param $params
     * @return array|bool
     * @throws Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function replyEmail($params)
    {
        $return = [
            //status 0 一般错误，1发送成功，2并发超三个，3每分钟超30条，4每天超10000条，5 3或者4
            'status'=>0,
            'message'=>'',
        ];

        $platform= '';
        $receiver=[];
        $isSent='';
        $subject='';
        $account_code='';

        if (isset($params['platform']) && in_array($params['platform'], ['1', '2'])) {
            $platform = $params['platform'];
        }
        if (isset($params['receiver']) && $params['receiver'] != '') {
            $receiver = json_decode($params['receiver'], true);
        }
        if (isset($params['subject']) && $params['subject'] != '') {
            $subject = $params['subject'];
        }

        $validate = new EbayEmailValidate();
        if (!$validate->scene('reply')->check($params)) {
            throw new Exception($validate->getError(), 400);
        }

        $receivedMail = EbayEmailList::where(['id' => $params['reply_email_id'], 'type' => 1])->find();
        if (!$receivedMail) {
            throw new Exception('未找到需要回复邮件,请确认是否存在', 404);
        }
//        if ($receivedMail->is_replied == 1) {
//            throw new Exception('该邮件已被回复过', 400);
//        }
        $email_accountId = $receivedMail->account_id;
//        $accountInfo = EmailAccounts::where(['id' => $email_accountId])->find();

        $accountInfo = accountModel::where(['a.channel_id' => ChannelAccountConst::channel_ebay, 'a.email' => $receivedMail['receiver']])->alias('a')
            ->join('email e','e.id=a.email_id','LEFT')
            ->field('e.email, e.password, e.post_id, e.status, e.is_send')->find();

        if (empty($accountInfo)) {
            throw new Exception('被回复邮件对应的平台账号未找到，请检查是否被移除', 404);
        } elseif ($accountInfo['status'] == 0 || $accountInfo['is_send'] == 0) {
            throw new Exception('被回复邮件对应的平台账号不具有发送邮件的权限', 401);
        }

        $accountInfo['email_password'] = $this->encryption->decrypt($accountInfo['password']); //密码解密

        $emailService = ModelPostoffice::where([ 'id' => $accountInfo['post_id'] ])->find();

        //在邮件里面有单号的时候；
        $replace = '';
        if (!empty($receivedMail['order_no'])) {
            $order = (new OrderService())->searchOrder(['order_number_type' => 'channel', 'order_number' => $receivedMail->order_no]);
            if ($order) {
                $replace = [
                    $order['buyer_id'] ?: '',
                    $order['buyer'] ?: '',
                    $order['channel_order_number'] ?: '',
                    $order['shipping_name'] ?: '',
                    $order['shipping_number'] ?: '',
                ];
            }
        }

        if(strtoupper($params['method']) == 'RE'){
            $subject = 'RE: ' . $receivedMail->subject;
        }else{
            $subject = 'FW: ' . $subject;
        }
        $content = str_replace(static::$tplFields, $replace, $params['content']);
        if (empty($content)) {
            throw new Exception('回复内容未设置', 400);
        }
        $attachFile = $this->getUploadAttachment($params, $this->sendMailAttachRoot, $accountInfo['email']);
        //不为空，则添加；
        $tmp_attachment = [];
        empty($attachFile) || $tmp_attachment[] = str_replace(ROOT_PATH, '', $attachFile);
        $createUserId = Common::getUserInfo()['user_id'];

        if(strtolower($platform)  == 'ebay'){
            $cache = Cache::store('EbayAccount');
            $account = $cache->getTableRecord($email_accountId);
            $account_code = empty($account['code'])?'':$account['code'];
        }

        $receiver_mail = implode(",", $receiver);

        $recordId = $this->addSendRecord([
            'type' => 2,
            'platform' => $platform,
            'email_account_id' => $email_accountId,
            'account_id' => $email_accountId,
            'account_code' => $account_code,
            'buyer_name' => empty($receivedMail['buyer_name'])? (isset($order['buyer']) ? $order['buyer'] : '') : $receivedMail['buyer_name'],
            'reply_email_id' => $receivedMail->id,
            'email_account' => $accountInfo['email'],
            'buyer_account' => $receivedMail['buyer_name'],
            'buyer_email' => $receiver_mail ? $receiver_mail : $receivedMail['sender'],
            'creator_id' => $createUserId,
            'order_no' => $receivedMail->order_no,
            'subject' => $subject,
            'attachment' => $tmp_attachment,
            'content' => $content,
        ]);
        //发送邮件的帐号和smtp服务器；
        $account = new EmailAccount(
            $receivedMail['account_id'],
            $accountInfo['email'],
            $accountInfo['email_password'],
            $emailService['imap_url'],
            $emailService['imap_port'],
            $emailService['smtp_url'],
            $emailService['smtp_port'],
            ($platform==1)?'ebay':'paypal'
        );
        if(strtoupper($params['method']) == 'RE'){
            $return = $this->sendMail($account, $receivedMail['sender'], $recordId, $subject, $content, $attachFile);
        }else{
            foreach ($receiver as $rec) {
                $return = $this->sendMail($account, $rec, $recordId, $subject, $content, $attachFile);
                if (!($return['status']==1)) {
//                    throw new Exception($this->mailSendError, 400);
                    return $return;
                }
            }
        }
        //发送成功
        if ($return['status']==1) {
            $receivedMail->is_replied = 1;      //更新被回复邮件的回复状态
            $receivedMail->isUpdate()->save();
        }
//        else {
//            throw new Exception($this->mailSendError, 400);
//        }
        return $return;
    }


    /**
     * 添加发送记录
     * @param $params
     * @return int
     * @throws Exception
     */
    protected function addSendRecord($params)
    {
        Db::startTrans();
        try {
            $contentModel = new EbayEmailContent();

            $listData = [
                'type' => $params['type'],
                'email_account_id' => $params['email_account_id'],
                'account_id' => $params['account_id'],
                'account_code' => $params['account_code'],
                'platform' => $params['platform'],
                'reply_email_id' => $params['reply_email_id'] ?? 0,
                'buyer_name' => $params['buyer_name'],
                'sender' => $params['email_account'],
                'receiver' => $params['buyer_email'],
//                'order_no' => $params['order_no'],
                'subject' => $params['subject'],
                'attachment' => json_encode($params['attachment'], JSON_UNESCAPED_UNICODE),
                'status' => 0,
                'creator_id' => $params['creator_id'],
                'create_time' => time(),
                'sender_id' => $this->save_email_address($params['email_account'], $params['platform'], 1),
                'receiver_id' => $this->save_email_address($params['buyer_email'], $params['platform'], 2),
            ];
            $id = $this->ebayEmailModel->insertGetId($listData);
            $contentModel->insert([
                'list_id' => $id,
                'content' => $params['content'],
            ]);
            Db::commit();
            return $id;
        } catch (Exception $ex) {
            Db::rollback();
            throw new Exception($ex->getMessage(), 500);
        }
    }

    /**
     * @param $params
     * @param $attachRootDir
     * @param $accountDir
     * @return string
     * @throws Exception
     */
    protected function getUploadAttachment(&$params, $attachRootDir, $accountDir)
    {
        $atthData = isset($params['file_data']) ? $params['file_data'] : '';
        $attachFile = '';
        if (!empty($atthData)) {
            $fileName = isset($params['file_name']) ? $params['file_name'] : '';
            if (empty($fileName)) {
                throw new Exception('附件名称未设置', 400);
            }
            $attachDir = $attachRootDir . DIRECTORY_SEPARATOR . $accountDir;
            if (!is_dir($attachDir) && !mkdir($attachDir, 0777, true)) {
                throw new Exception('附件上传目录创建失败', 401);
            }
            $attachFile = $attachDir . DIRECTORY_SEPARATOR . $fileName;
            AmazonEmailHelper::saveFile($atthData, $attachFile);
        }
        return $attachFile;
    }



    /**
     * @param EmailAccount $emailAccount
     * @return int
     * @throws Exception
     */
    public function receiveEmail(EmailAccount $emailAccount, $email_account_id)
    {
        try {
            $syncQty = 0;
            $mailReceiver = MailReceiver::getInstance();
            $mailReceiver->setEmailAccount($emailAccount);
            $mailsIds = $mailReceiver->searchMailbox('ALL');

            if (empty($mailsIds) && MailReceiver::$isError) {
                throw new Exception(MailReceiver::$lastError, 500);
            }

            //查出上一次保存的UID，比这个UID小的，就可以跳过了；
//            $email = $emailAccount->getEmailAccount();
            $maxUid = Cache::store('EbayEmail')->getMaxUid($email_account_id);

            $cache = Cache::store('EbayAccount');

            $contentModel = new EbayEmailContent();
            $keywordMatching = new KeywordMatching();

            foreach ($mailsIds as $id) {
                if ($id <= $maxUid) {
                    //比保存的邮件uid小的肯定是下载过的，直接跳过；
                    continue;
                }
                //$mail = $mailReceiver->getMail($id, false);
                $mail = null;
                for ($i = 0; $i <= 3; $i++) {
                    try {
                        $mail = $mailReceiver->getMail($id, false);
                        break;
                    } catch (Exception $e) {
                        if($i  >= 3) {
                            throw new Exception($e->getMessage());
                        }
                    }
                }

                if (!$mail) {
                    continue;
                }

                $syncQty++;
                $attachs = $mail->getAttachments();

                $json = [];
                if ($attachs) {
                    foreach ($attachs as $attach) {
                        $json[] = [
                            'name' => $attach->name,
                            'path' => mb_substr($attach->filePath, mb_strlen(ROOT_PATH) - 1),
                        ];
                    }
                }

                /**
                 * 保存邮件
                 */
                Db::startTrans();
                try {

                    //插入邮件数据；
                    $data = [];
                    $data['email_account_id'] = $email_account_id;
                    $data['account_id'] = $emailAccount->getPlatformAccount();
                    $data['email_uid'] = $id;
                    $data['receiver'] = $emailAccount->getEmailAccount();
                    $data['sender'] = $mail->fromAddress;
                    //日期格式 'Mon, 3 Dec 2018 17:16:08 -0700 (GMT-07:00)' strtotime识别不了
                    $data['sync_time'] = strtotime($mail->date)?: time();

                    if(strtolower($mail->platformName)  == 'ebay'){
                        $data['platform'] = 1;
                        $account = $cache->getTableRecord($emailAccount->getPlatformAccount());
                        $data['account_code'] = empty($account['code'])?'':$account['code'];
                    }else{
                        $data['platform'] = 2;
                    }
                    $data['site'] = $mail->site;
                    $data['order_no'] = $mail->orderNo;
                    $data['box_id'] = $mail->box;
                    $data['type'] = 1;
                    $data['create_time'] = time();
                    $data['is_replied'] = 2;
                    $data['subject'] = mb_substr($this->convertToUtf8($mail->subject), 0, 1000, 'utf-8');
                    $data['infringement'] = $this->check_infringement_email($mail->subject, $data['account_code'], $data['account_id'],$data['sync_time']) ? 1 : 0;
                    $data['attachment'] = json_encode($json);

                    $data['sender_id'] = $this->save_email_address($data['sender'], $data['platform'], 1);

                    $data['receiver_id'] = $this->save_email_address($data['receiver'], $data['platform'], 2);

                    $this->ebayEmailModel->insert($data);
                    $last_id = $this->ebayEmailModel->getLastInsID();


                    $content = $this->convertToUtf8($mail->getBody());
                    //插入邮件内容表数据；
                    $contentModel->insert([
                        'list_id' => $last_id,
                        'content' => $content,
                    ]);

                    Db::commit();

                    /**
                     * 关键词匹配
                     */
                    $param = [
                        'channel_id'=>1,
                        'message_id'=>$last_id,
                        'account_id'=>$data['account_id'],
                        'message_type'=>1,
                        'buyer_id'=>$data['sender'],
                        'receive_time'=>$data['sync_time'],
                    ];
                    $keywordMatching->keyword_matching($content,$param);

                    unset($mail, $data);
                    //$mailReceiver->markMailAsRead($id);//下载成功后，把邮件标记为已读，防止下次下载；
//                    Cache::store('EbayEmail')->setMaxUid($email, $id);//保存下载的邮件ID
                } catch (Exception $e) {
                    Db::rollback();
                    throw $e;
                }
            }

            unset($mailReceiver);
            unset($email);
            unset($maxUid);
            gc_collect_cycles();
            return $syncQty;
        } catch (\Exception $ex) {
//            Cache::handler()->hSet('hash:email_sync_log:' . MailReceiver::$syncingEmailAccount, date('YmdHis'), $ex->getMessage());
            throw new Exception('Message:'. $ex->getMessage(). '; File:'. $ex->getFile(). ';Line:'. $ex->getLine(). ';');
        }
    }

    protected function convertToUtf8($string = '')
    {
        $encode = mb_detect_encoding($string, array("ISO-8859-1","ASCII","UTF-8","GB2312","GBK","BIG5","HKSCS","GB18030"));
        if ($encode){
            $string = iconv($encode,"UTF-8",$string);
        }else{
            $string = iconv("UTF-8","UTF-8//IGNORE",$string);   //识别不了的编码就截断输出
        }
        return $string;
    }

    /**
     * 正则匹配是否是侵权邮件
     * @param $subject
     * @return bool
     */
    private function check_infringement_email($subject, $account_code, $account_id, $sync_time)
    {
        $patten = '/Your listing has been removed|Your selling privileges have been temporarily restricted/i';
        $report_time = time() - 3*24*60;
        //侵权邮件而且是最近三天
        if (preg_match($patten, $subject) && $sync_time > $report_time) {
            $this->send_ding_message($subject, $account_code, $account_id);
            return true;
        }else {
            return false;
        }
    }

    /**
     * 发送钉钉工作消息
     * @param $subject
     * @param $account_code
     */
    private function send_ding_message($subject, $account_code, $account_id)
    {
        $channelUserAccountMap = new ChannelUserAccountMap();
        $ids = $channelUserAccountMap->where(['channel_id' => ChannelAccountConst::channel_ebay, 'account_id' => $account_id])
            ->field('seller_id,customer_id')->select();
        $seller_id = array_column($ids, 'seller_id');
        $customer_id = array_column($ids, 'customer_id');
        $receive_ids = array_merge($seller_id,$customer_id);
        $receive_ids = array_unique($receive_ids);
        $params = [
            'receive_ids'=>$receive_ids,
            'title'=>'侵权产品邮件通知',
            'content'=>"侵权产品邮件通知：    
            账号简称：$account_code   
            主题：$subject   
            更多详情请至ERP侵权产品邮件收件箱查看",
            'type'=>13,
            'dingtalk'=>1,
            'create_id' => 3152
        ];
        InternalLetterService::sendLetter($params);
    }

    /**
     * 正常情况下，根据邮件UID肯定是可以拿到邮件，但是有时因为网络或服务器问题会出现异常导致获取邮件失败，出现异则重试几次
     * @param $mailReceiver
     * @param $mail_uid
     * @param int $testnum 出现异常的重试次数;
     * @return mixed
     */
    public function getMail($mailReceiver, $mail_uid, $testnum = 3) {
        try {
            $mail = $mailReceiver->getMail($mail_uid, false);
            return $mail;
        } catch (Exception $e) {
            $testnum--;
            if($testnum  < 0) return false;
            return $this->getMail($mailReceiver, $mail_uid, $testnum);
        }
    }


    /**
     * Ebay重新发送邮件
     * @param $params
     * @return bool
     * @throws Exception
     */
    public function reSendMail($params)
    {
        $return = [
            //status 0 一般错误，1发送成功，2并发超三个，3每分钟超30条，4每天超10000条，5 3或者4
            'status'=>0,
            'message'=>'',
        ];

        $platform= '';
        if (isset($params['platform']) && in_array($params['platform'], ['ebay', 'paypal'])) {
            $platform = $params['platform'];
        }

        //传数组
        if(is_array($params)) {
            $mailId = isset($params['mail_id']) ? $params['mail_id'] : '';
        } else if (is_string($params) || is_int($params)) {
            $mailId = (int)$params;
        } else {
            throw new Exception('邮件id类型不正确', 400);
        }
        if (empty($mailId)) {
            throw new Exception('邮件id未设置', 400);
        }
        //根据mailId找出邮件；
        $mail = EbayEmailList::where(['id' => $mailId])->find();
        if (empty($mail)) {
            throw new Exception('未找到需要发送的邮件', 404);
        } elseif ($mail['status'] == 1) {
            throw new Exception('该邮件已经发送成功，无需重发', 404);
        }
        $content = EbayEmailContent::where('list_id', $mailId)->find();

//        $emailAccount = EmailAccounts::where('id', $mail['email_account_id'])->find();

        $emailAccount = accountModel::where(['a.channel_id' => ChannelAccountConst::channel_ebay, 'a.email' => $mail['receiver']])->alias('a')
            ->join('email e','e.id=a.email_id','LEFT')
            ->field('e.email, e.password, e.post_id, e.status, e.is_send')->find();

        $emailAccount['password'] = $this->encryption->decrypt($emailAccount['password']); //密码解密

        $emailService = ModelPostoffice::where([ 'id' => $emailAccount['post_id'] ])->find();


        if (empty($emailAccount)) {
            throw new Exception('未找到邮件发送账号,请检查是否被删除', 404);
        } elseif ($emailAccount['status'] == 0 || $emailAccount['is_send'] == 0) {
            throw new Exception('邮件发送账号不具有发送邮件的权限,请检查是否被修改', 401);
        }
        $mail['attachment'] = json_decode($mail['attachment'], true);
        if (!empty($mail['attachment'])) {
            if (is_array($mail['attachment'])) {
                foreach ($mail['attachment'] as &$val) {
                    $val =  ROOT_PATH . $val;
                }
            } else {
                $mail['attachment'] = ROOT_PATH . $mail['attachment'];
            }
        }
        $account = new EmailAccount(
            $mail['account_id'],
            $emailAccount['email'],
            $emailAccount['email_password'],
            $emailService['imap_url'],
            $emailService['imap_port'],
            $emailService['smtp_url'],
            $emailService['smtp_port'],
            $platform);
        $return = $this->sendMail($account, $mail['receiver'], $mailId, $mail->subject, $content->content, $mail->attachment);
//        if ($isSent) {
//            return true;
//        } else {
//            throw new Exception($this->mailSendError, 400);
//        }
        return $return;
    }

    public function getCustormer($accountId) {

        $data = User::table('user')
            ->join('channel_user_account_map map','user.id =map.customer_id')
            ->field('user.id as customer_id,user.realname')
            ->where([
                'map.channel_id'=> ChannelAccountConst::channel_ebay,
                'map.account_id' => $accountId
            ])->select();
        return $data;
    }


    /**
     * 标记邮件为已读
     * @param number $id 邮件id
     * @throws JsonErrorException
     * @return boolean
     */
    function markRead( $ids )
    {
        $ids = json_decode($ids, true);
        foreach ($ids as $key => $id) {
            //查看邮件是否存在；
            $email = $this->ebayEmailModel->where(['id' => $id])->find();
            if (empty($email)) {
                throw new JsonErrorException('数据不存在！');
            }
            $result=$this->ebayEmailModel->update(['is_read' => 1], ['id' => $id]);
            if($result === false)
                return false;
        }
        return true;

    }

    /**
     * 标记邮件为未读
     * @param number $id 邮件id
     * @throws JsonErrorException
     * @return boolean
     */
    function markUnRead( $ids )
    {
        $ids = json_decode($ids, true);
        foreach ($ids as $key => $id) {
            //查看邮件是否存在；
            $email = $this->ebayEmailModel->where(['id' => $id])->find();
            if (empty($email)) {
                throw new JsonErrorException('数据不存在！');
            }
            $result = $this->ebayEmailModel->update(['is_read' => 0], ['id' => $id]);
            if($result === false)
                return false;
        }
        return true;
    }

    /**
     * 标记邮件为垃圾邮件
     * @param number $id 邮件id
     * @throws JsonErrorException
     * @return boolean
     */
    function marktrash( $ids )
    {
        $ids = json_decode($ids, true);
        foreach ($ids as $key => $id) {
            //查看邮件是否存在；
            $email = $this->ebayEmailModel->where(['id' => $id])->find();
            if (empty($email)) {
                throw new JsonErrorException('数据不存在！');
            }
            $result = $this->ebayEmailModel->update(['type' => 3], ['id' => $id]);
            if($result === false)
                return false;
        }
        return true;
    }

    /**
     * 标记邮件置顶
     * @param number $id 邮件id
     * @throws JsonErrorException
     * @return boolean
     */
    function markTop( $ids )
    {
        $ids = json_decode($ids,true);
        foreach ($ids as $key => $id) {
            //查看邮件是否存在；
            $email = $this->ebayEmailModel->where(['id' => $id])->find();
            if (empty($email)) {
                throw new JsonErrorException('数据不存在！');
            }
            $result = $this->ebayEmailModel->update(['top_time'=> time(), 'id' => $id]);
            if($result === false)
                return false;
        }
        return true;
    }

    /**
     * 取消邮件置顶
     * @param number $id 邮件id
     * @throws JsonErrorException
     * @return boolean
     */
    function cancelTop( $ids )
    {
        $ids = json_decode($ids,true);
        foreach ($ids as $key => $id) {
            //查看邮件是否存在；
            $email = $this->ebayEmailModel->where(['id' => $id])->find();
            if (empty($email)) {
                throw new JsonErrorException('数据不存在！');
            }
            $result = $this->ebayEmailModel->update(['top_time'=> 0, 'id' => $id]);
            if($result === false)
                return false;
        }
        return true;
    }

    /**
     * 垃圾邮件转到收件箱
     * @param  邮件id
     * @throws JsonErrorException
     * @return boolean
     */
    function turnToInbox( $ids )
    {
        $ids = json_decode($ids, true);
        foreach ($ids as $key => $id) {
            //查看邮件是否存在；
            $email = $this->ebayEmailModel->where(['id' => $id])->find();
            if (empty($email)) {
                throw new JsonErrorException('数据不存在！');
            }
            $result = $this->ebayEmailModel->update(['type' => 1], ['id' => $id]);
            if($result === false)
                return false;
        }
        return true;
    }

    /**
     * ebay帐号数量；
     */
    public function getEbayAccountMessageTotal($params) {

        $where=[];
        $where = $this->getEmailCondition($params);
        $this->uid = Common::getUserInfo()['user_id'];

        //标题搜索
        if (isset($params['snText']) && $params['snText'] != '') {
            $snText = trim($params['snText']);
            $snText='%'.$snText.'%';
//            $where['content'] = ['like', $snText];
            $where['subject'] = ['like', $snText];
        }

        if (isset($params['sender_id']) && $params['sender_id']!='') {
            $where['sender_id'] = $params['sender_id'];
        }

        // 0未读 1已读
//        $where['is_read'] = 0;

        //当不是test用户登录时；
        $accountIds = [];
        //测试用户和超级管理员用户；
        if ($this->uid == 0 || $this->isAdmin($this->uid)) {
            $accountIds = ChannelUserAccountMap::where([
                'channel_id' => $this->channel_id
            ])->column('account_id');
        } else {
            $uids = $this->getUnderlingInfo($this->uid);
            $accountIds = ChannelUserAccountMap::where([
                'customer_id' => ['in', $uids],
                'channel_id' => $this->channel_id
            ])->column('account_id');
        }

        if (empty($where['account_id'])) {
            $where['account_id'] = ['in', $accountIds];
        }

        //以下为ebay帐号；
        $groupList = $this->ebayEmailModel->where($where)
            ->group('account_id')
            ->field('count(id) count, account_id')
            ->order('count', 'desc')
            ->select();

        $newAccounts = [];
//        if (!empty($groupList)) {
        $sort = [];
        foreach ($accountIds as $accountId) {
            $sort[$accountId] = 0;
        }
        foreach ($groupList as $group) {
            $sort[$group['account_id']] = $group['count'];
        }
        arsort($sort);
        $cache = Cache::store('EbayAccount');
        $allTotal = 0;
        foreach ($sort as $account_id => $total) {
            $account = $cache->getTableRecord($account_id);
            $tmp = [];
            $tmp['value'] = $account_id;
            $tmp['label'] = $account['code'] ?? $account_id;
            $tmp['count'] = $total;
            $newAccounts[] = $tmp;
            $allTotal += $total;
        }
        array_unshift($newAccounts, [
            'value' => '',
            'label' => '全部',
            'count' => $allTotal
        ]);
//        }

        if(empty($newAccounts)){
            array_unshift($newAccounts, [
                'value' => '',
                'label' => '全部',
                'count' => 0
            ]);
        }

        return ['data' => $newAccounts];
    }


    /*
     * 收件人邮件地址列表
     * return arrray
     */
    public function ReceiverMailsAddr($params){

        $page = $params['page'] ?: 1;
        $pageSize = $params['pageSize'] ?: 10;
        $content = $params['content']?? '';
        $where = [];

        $where['type'] = 2;
        if (!empty($content)) {
            $where['email'] = ['like', '%' . $content . '%'];
        }

        $count = Db::table('ebay_email_address')->where($where)->count();
        $senderList = Db::table('ebay_email_address')->field('id,email')->where($where)->page($page, $pageSize)->select();

        $result = [
            'data' => $senderList,
            'page' => $page,
            'pageSize' => $pageSize,
            'count' => $count,
        ];
        return $result;
    }


    /*
     * 发件人邮件地址列表
     * return arrray
     */
    public function SenderMailsAddr($params){

        $page = $params['page'] ?: 1;
        $pageSize = $params['pageSize'] ?: 10;
        $content = $params['content']?? '';
        $where = [];

        $where['type'] = 1;
        if (!empty($content)) {
            $where['email'] = ['like', '%' . $content . '%'];
        }
        $count = Db::table('ebay_email_address')->where($where)->count();
        $senderList = Db::table('ebay_email_address')->field('id,email')->where($where)->page($page, $pageSize)->select();

        $result = [
            'data' => $senderList,
            'page' => $page,
            'pageSize' => $pageSize,
            'count' => $count,
        ];
        return $result;
    }

    /*
     * 未读邮件数量
     * return int
     */
    public function unreadAmount($params){
        $where = $this->getEmailCondition($params);

        //标题搜索
        if (isset($params['snText']) && $params['snText'] != '') {
            $snText = trim($params['snText']);
            $snText='%'.$snText.'%';
//            $where['content'] = ['like', $snText];
            $where['subject'] = ['like', $snText];
        }

        if (isset($params['sender_id']) && $params['sender_id']!='') {
            $where['sender_id'] = $params['sender_id'];
        }

        $where['is_read'] = 0;
        $count = $this->ebayEmailModel->where($where)->count();
        return $count;
    }


    /**
     * 收件箱
     * @param $params
     * @return mixed
     */
    public function inbox($params)
    {
        $page = $params['page'] ?? 1;
        $pageSize = $params['pageSize'] ?? 10;

        $where = $this->getEmailCondition($params);

        //标题搜索
        if (isset($params['snText']) && $params['snText'] != '') {
            $snText = trim($params['snText']);
            $snText='%'.$snText.'%';
//            $where['content'] = ['like', $snText];
            $where['subject'] = ['like', $snText];
        }

        if (isset($params['sender_id']) && $params['sender_id']!='') {
            $where['sender_id'] = $params['sender_id'];
        }

        if (isset($params['sort']) && $params['sort']) {
            $order = 'top_time desc, sync_time ';
            $order .= strtolower($params['sort']) == 'desc' ? ' DESC' : ' ASC';
        }
        $order = isset($order) ? $order : 'top_time desc, sync_time desc ';

        $ebayEmailList = $this->ebayEmailModel->alias('e')
//            ->join(['ebay_email_content' => 'c'], 'e.id=c.list_id')
            ->where($where)
            ->field('id, sender, receiver, sync_time, is_read, account_id, subject, account_code')
            ->order($order)
            ->page($page, $pageSize)
            ->select();

        foreach ($ebayEmailList as $email){
            $email['body'] = $this->ebayEmailContentModel->where('list_id', $email['id'])->value('content');
//            $email['sender'] = Db::table('ebay_email_address')->where(['id'=>$email['sender_id']])->value('email');
//            $email['receiver'] = Db::table('ebay_email_address')->where(['id'=>$email['receiver_id']])->value('email');
        }

        $count = $this->ebayEmailModel->alias('e')
//            ->join(['ebay_email_content' => 'c'], 'e.id=c.list_id')
            ->where($where)
            ->count();

        $result = [
            'data' => $ebayEmailList,
            'count' => $count,
            'page' => $page,
            'pageSize' => $pageSize
        ];
        return $result;

    }

    /**
     * 侵权邮件收件箱
     * @param $params
     * @return mixed
     */
    public function infringementBox($params)
    {
        $page = $params['page'] ?? 1;
        $pageSize = $params['pageSize'] ?? 10;

        $where = $this->getEmailCondition($params);

        //标题搜索
        if (isset($params['snText']) && $params['snText'] != '') {
            $snText = trim($params['snText']);
            $snText='%'.$snText.'%';
//            $where['content'] = ['like', $snText];
            $where['subject'] = ['like', $snText];
        }

        if (isset($params['sender_id']) && $params['sender_id']!='') {
            $where['sender_id'] = $params['sender_id'];
        }

        if (isset($params['sort']) && $params['sort']) {
            $order = 'top_time desc, sync_time ';
            $order .= strtolower($params['sort']) == 'desc' ? ' DESC' : ' ASC';
        }
        $order = isset($order) ? $order : 'top_time desc, sync_time desc ';



        $ebayEmailList = $this->ebayEmailModel->alias('e')
//            ->join(['ebay_email_content' => 'c'], 'e.id=c.list_id')
            ->where($where)
            ->field('id, sender, receiver, sync_time, is_read, account_id, subject, account_code')
            ->order($order)
            ->page($page, $pageSize)
            ->select();

        foreach ($ebayEmailList as $email){
            $email['body'] = $this->ebayEmailContentModel->where('list_id', $email['id'])->value('content');
//            $email['sender'] = Db::table('ebay_email_address')->where(['id'=>$email['sender_id']])->value('email');
//            $email['receiver'] = Db::table('ebay_email_address')->where(['id'=>$email['receiver_id']])->value('email');
        }

        $count = $this->ebayEmailModel->alias('e')
//            ->join(['ebay_email_content' => 'c'], 'e.id=c.list_id')
            ->where($where)
            ->count();

        $result = [
            'data' => $ebayEmailList,
            'count' => $count,
            'page' => $page,
            'pageSize' => $pageSize
        ];
        return $result;

    }

    /**
     * 发件箱
     * @param $params
     * @return mixed
     */
    public function outbox($params)
    {
        $page = $params['page'] ?? 1;
        $pageSize = $params['pageSize'] ?? 10;

        $where = $this->getEmailCondition($params);

        if (isset($params['sort']) && $params['sort']) {
            $order = 'sync_time';
            $order .= strtolower($params['sort']) == 'desc' ? ' DESC' : ' ASC';
        }
        $order = isset($order) ? $order : 'sync_time desc';

        if (isset($params['status']) && in_array($params['status'], ['1', '2'])) {
            $where['status'] = $params['status'];
        }

        $ebayEmailList = $this->ebayEmailModel->alias('e')
//            ->join(['ebay_email_content' => 'c'], 'e.id=c.list_id')
            ->where($where)
            ->field('id, sender, receiver, sync_time, account_id, subject, account_code')
            ->order($order)
            ->page($page, $pageSize)
            ->select();

        foreach ($ebayEmailList as $email){
            $email['body'] = $this->ebayEmailContentModel->where('list_id', $email['id'])->value('content');
//            $email['sender'] = Db::table('ebay_email_address')->where(['id'=>$email['sender_id']])->value('email');
//            $email['receiver'] = Db::table('ebay_email_address')->where(['id'=>$email['receiver_id']])->value('email');
        }

        $count = $this->ebayEmailModel->alias('e')
//            ->join(['ebay_email_content' => 'c'], 'e.id=c.list_id')
            ->where($where)
            ->count();

        $result = [
            'data' => $ebayEmailList,
            'count' => $count,
            'page' => $page,
            'pageSize' => $pageSize
        ];
        return $result;

    }

    /**
     * 垃圾箱
     * @param $params
     * @return mixed
     */
    public function trashbox($params)
    {
        $page = $params['page'] ?? 1;
        $pageSize = $params['pageSize'] ?? 10;

        $where = $this->getEmailCondition($params);

        if (isset($params['sort']) && $params['sort']) {
            $order = 'sync_time';
            $order .= strtolower($params['sort']) == 'desc' ? ' DESC' : ' ASC';
        }
        $order = isset($order) ? $order : 'sync_time desc';

        $ebayEmailList = $this->ebayEmailModel->alias('e')
//            ->join(['ebay_email_content' => 'c'], 'e.id=c.list_id')
            ->where($where)
            ->field('id, sender, receiver, sync_time, is_read, account_id, subject, account_code')
            ->order($order)
            ->page($page, $pageSize)
            ->select();

        foreach ($ebayEmailList as $email){
            $email['body'] = $this->ebayEmailContentModel->where('list_id', $email['id'])->value('content');
//            $email['sender'] = Db::table('ebay_email_address')->where(['id'=>$email['sender_id']])->value('email');
//            $email['receiver'] = Db::table('ebay_email_address')->where(['id'=>$email['receiver_id']])->value('email');
        }

        $count = $this->ebayEmailModel->alias('e')
//            ->join(['ebay_email_content' => 'c'], 'e.id=c.list_id')
            ->where($where)
            ->count();

        $result = [
            'data' => $ebayEmailList,
            'count' => $count,
            'page' => $page,
            'pageSize' => $pageSize
        ];
        return $result;
    }

    public function set_ebay_email_sender_id()
    {
        set_time_limit(0);

        $return = [
            'success_count'=>0,
            'error_count'=>0
        ];
        $pageSize = 10000;
        //成功失败计数
        $success_count = $error_count = 0;
        //临时存储sender_id
        $sender_arr = [];

        $where['sender_id'] = 0;

        //循环查询
        while ($datas = Db::table('ebay_email')->where($where)->field('id,sender')->limit($pageSize)->select())
        {
            foreach ($datas as $data) {

                //发件人为空，不处理
                if(empty($data['sender'])){
                    Db::table('ebay_email')->where(['id'=>$data['id']])->update(['sender_id'=>-1]);
                    $error_count++;
                    continue;
                }

                $ees_id = 0;
                if(isset($sender_arr[$data['sender']])){
                    $ees_id = $sender_arr[$data['sender']];
                }

                if(!$ees_id){
                    //未查到发件人，不处理
                    if(!$ees_row = Db::table('ebay_email_address')->where(['email'=>$data['sender'],'type'=>1])->field('id')->find()){
                        Db::table('ebay_email')->where(['id'=>$data['id']])->update(['sender_id'=>-1]);
                        $error_count++;
                        continue;
                    }
                    $ees_id = $ees_row['id'];
//                    $sender_arr[$data['sender']] = $ees_id;
                }

                Db::table('ebay_email')->where(['id'=>$data['id']])->update(['sender_id'=>$ees_id]);
                $success_count++;
            }
        }

        //返回处理结果
        $return['success_count'] = $success_count;
        $return['error_count'] = $error_count;
        return $return;
    }

    public function set_ebay_email_receiver_id()
    {
        set_time_limit(0);

        $return = [
            'success_count'=>0,
            'error_count'=>0
        ];
        $pageSize = 10000;
        //成功失败计数
        $success_count = $error_count = 0;
        //临时存储sender_id
        $sender_arr = [];

        $where['receiver_id'] = 0;

        //循环查询
        while ($datas = Db::table('ebay_email')->where($where)->field('id,receiver')->limit($pageSize)->select())
        {
            foreach ($datas as $data) {

                //收件人为空，不处理
                if(empty($data['receiver'])){
                    Db::table('ebay_email')->where(['id'=>$data['id']])->update(['receiver_id'=>-1]);
                    $error_count++;
                    continue;
                }

                $eer_id = 0;
                if(isset($sender_arr[$data['receiver']])){
                    $eer_id = $sender_arr[$data['receiver']];
                }

                if(!$eer_id){
                    //未查到收件人，不处理
                    if(!$eea_row = Db::table('ebay_email_address')->where(['email'=>$data['receiver'],'type'=>2])->field('id')->find()){
                        Db::table('ebay_email')->where(['id'=>$data['id']])->update(['receiver_id'=>-1]);
                        $error_count++;
                        continue;
                    }
                    $eer_id = $eea_row['id'];
//                    $sender_arr[$data['receiver']] = $eer_id;
                }

                Db::table('ebay_email')->where(['id'=>$data['id']])->update(['receiver_id'=>$eer_id]);
                $success_count++;
            }
        }

        //返回处理结果
        $return['success_count'] = $success_count;
        $return['error_count'] = $error_count;
        return $return;
    }

    /**
     * @param $email
     * @param $platform
     * @param $type
     * @return int|mixed|string
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function save_email_address($email, $platform, $type)
    {
        $data = [];
        $email_address = Db::table('ebay_email_address')->field('id')->where(['email' => $email,'type'=>$type])->find();
        if (empty($email_address)) {
            $data['email'] = $email;
            $data['create_time'] = time();
            $data['platform'] = $platform;
            $data['type'] = $type;
            $email_id = Db::table('ebay_email_address')->insertGetId($data);
        } else {
            $email_id = $email_address['id'];
        }
        return $email_id;
    }

}
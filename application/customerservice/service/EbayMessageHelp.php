<?php

namespace app\customerservice\service;

use app\common\cache\Cache;
use app\common\model\ChannelUserAccountMap;
use app\common\model\ebay\EbayMessage as EbayMessageModel;
use app\common\model\ebay\EbayMessageBody as EbayMessageBodyModel;
use app\common\model\ebay\EbayOrder;
use app\common\model\Order as OrderModel;
use app\common\model\OrderDetail;
use app\common\model\OrderPackage;
use app\common\model\Role;
use app\common\model\RoleUser;
use app\common\service\ChannelAccountConst;
use app\common\service\Filter;
use app\common\service\Report;
use app\common\service\UniqueQueuer;
use app\customerservice\filter\EbayAccountFilter;
use app\customerservice\filter\EbayCustomerFilter;
use app\customerservice\filter\EbayDepartmentFilter;
use app\customerservice\queue\EbaySendMessageQueue;
use app\order\service\OrderService;
use org\Curl;
use think\Config;
use think\Exception;
use erp\AbsServer;
use think\Db;
use think\File;
use think\Request;
use app\common\service\Common;
use service\ebay\EbayMessageApi;
use app\common\model\ebay\EbayMessageGroup as EbayMessageGroupModel;
use app\common\exception\JsonErrorException;
use app\common\cache\driver\EbayMessage;
use app\common\model\OrderSourceDetail;
use app\common\model\ebay\EbayFeedback;
use app\common\model\ebay\EbayCase;
use app\common\model\ebay\EbayRequest;
use app\common\model\ebay\EbayOrderDetail as EbayOrderDetailModel;
use app\common\model\Order;
use app\common\model\OrderAddress;
use app\common\service\OrderStatusConst;
use app\common\model\ebay\EbayListing as EbayListingModel;
use app\common\model\ebay\EbayMessageGroup;
use app\index\service\User;
use \app\common\model\User as UserModel;
use app\common\traits\User as UserTraits;
use app\index\service\AccountService;
use app\common\model\OrderSourceDetail as OrderSourceDetailModel;

/**
 * Created by tb
 * User: tanbin
 * Date: 2016/12/6
 * Time: 18:14
 */
class EbayMessageHelp extends AbsServer
{
    use UserTraits;

    // 站内信优先级
    private $message_level = [
        1 => '红旗',
        2 => '紫旗',
        3 => '蓝旗',
        4 => '墨绿',
        5 => '绿旗',
        6 => '橙旗',
        7 => '黄旗'
    ];

    /**
     * 站内信分组列表
     *
     * @param unknown $params
     * @param number $page
     * @param number $pageSize
     * @return unknown
     */
    function group_list($params, $page = 1, $pageSize = 10)
    {
        $lists = [];
        $condition = $this->getWhere($params);
        // 排序
        $sort = 'DESC';
        if (!empty($params['time_sort']) && $params['time_sort'] == 1) {
            $sort = 'ASC';
        }

        $ebayMessageGroupMode = new EbayMessageGroupModel();
        $field = 'g.id,g.account_id,g.sender_user,g.last_receive_time,g.last_message_id,g.item_id,g.untreated_count,g.msg_count,g.prior_level,g.status';

        if (empty($condition['o.status']) && empty($condition['e.flag_id'])) {
            $count = $ebayMessageGroupMode->alias('g')->field('g.id')->where($condition)->count();
            // 结果集

            $ebayMessageGroupMode = new EbayMessageGroupModel();
            $lists = $ebayMessageGroupMode->alias('g')
                ->field($field)
                ->where($condition)
                ->order('g.last_receive_time ' . $sort)
                ->page($page, $pageSize)
                ->select();
        } else {
            $count = $ebayMessageGroupMode->alias('g');
            if (!empty($condition['o.status'])) {
                $count = $count->join(['order' => 'o'], 'g.local_order_id=o.id');
            }
            if (!empty($condition['e.flag_id'])) {
                $count = $count->join(['ebay_message' => 'e'], 'e.group_id=g.id')->group('g.id');
            }
            $count = $count->field('g.id')->where($condition)->count('g.id');

            // 结果集
            $lists = $ebayMessageGroupMode->alias('g');
            if (!empty($condition['o.status'])) {
                $lists = $lists->join(['order' => 'o'], 'g.local_order_id=o.id');
            }
            if (!empty($condition['e.flag_id'])) {
                $lists = $lists->join(['ebay_message' => 'e'], 'e.group_id=g.id')->distinct('g.id');
            }
            $lists = $lists->field($field)
                ->where($condition)
                ->order('g.last_receive_time ' . $sort)
                ->page($page, $pageSize)
                ->select();
        }

        $cache = Cache::store('EbayAccount');
        $new_list = [];
        $lastMessageId = [];
        foreach ($lists as $k => $v) {
            $lastMessageId[] = $v['last_message_id'];
            $data = array();
            $data['group_id'] = $v['id'];
            $data['sender'] = $v['sender_user'];
            $data['receive_new_date'] = !empty($v['last_receive_time']) ? date('Y-m-d H:i:s', $v['last_receive_time']) : '';
            $data['item_id'] = $v['item_id'];
            $data['level'] = $v['prior_level'] ? $v['prior_level'] : '';

            //接收账号
            $data['account_id'] = $v['account_id'];
            $account = $cache->getTableRecord($v['account_id']);
            $data['account_code'] = $account['code'] ?? '';

            $data['count_num'] = $v['untreated_count'] . '/' . $v['msg_count'];
            $data['untreated_count'] = $v['untreated_count'];
            $data['msg_count'] = $this->getGroupCount($v['id']);
            $data['item_url'] = 'http://www.ebay.com/itm/' . $v['item_id'];
            $data['item_img'] = $this->getImg($v['item_id']);
            $data['last_message_id'] = $v['last_message_id'];
            $data['status'] = $v['status'];
            $data['message_list'][0] = [];
            $new_list[] = $data;
        }

        $db_field = 'id,message_id,group_id,account_id,sender,send_to_name,message_type,replied,send_time,item_id,subject,level,flag_id,status,message_text as text,media_info';
        $messageModel = new EbayMessageModel();
        $messageList = $messageModel->field($db_field)->where(['message_id' => ['IN', $lastMessageId]])->column($db_field, 'group_id');
        foreach ($new_list as &$data) {
            if (!empty($messageList[$data['group_id']])) {
                $tmpMessage = $messageList[$data['group_id']];
            } else {
                $findMessage = $messageModel->field($db_field)
                    ->where([
                        'group_id' => $data['group_id'],
                        'message_type' => 1,
                    ])
                    ->order('send_time', 'desc')
                    ->limit(1)
                    ->select();
                //更新一下group
                if (!empty($findMessage[0])) {
                    $tmpMessage = $findMessage[0]->toArray();
                    $ebayMessageGroupMode->update([
                        'last_message_id' => $tmpMessage['message_id'],
                        'last_receive_time' => $tmpMessage['send_time'],
                        'status' => (empty($tmpMessage['status']) ? 0 : 1)
                    ], ['id' => $data['group_id']]);
                } else {
                    $tmpMessage = [];
                }
            }
            $data['message_list'][0] = $tmpMessage;
            $data['message_list'][0]['receive_date'] = !empty($data['message_list'][0]['send_time']) ? date('Y-m-d H:i:s', $data['message_list'][0]['send_time']) : '';
            $data['message_list'][0]['text'] = trim(html_entity_decode($data['message_list'][0]['text']));
            $data['message_list'][0]['account_code'] = $data['account_code'];
            if ($data['message_list'][0]['message_type'] == 3) {
                $data['message_list'][0]['title'] = $data['message_list'][0]['account_code'] . ' -> ' . $data['message_list'][0]['send_to_name'];
            } else {
                $data['message_list'][0]['title'] = $data['message_list'][0]['sender'] . ' -> ' . $data['message_list'][0]['account_code'];
            }
        }
        unset($data);
        return [
            'list' => $new_list,
            'count' => $count
        ];
    }


    /**
     * 封装条件
     *
     * @param array $params
     */
    function getWhere($params)
    {
        $where = [];
        //现在发站内信，如果是新item_id和买家，也会加分类，但不会加来信总数，所以这里要去一下；
        $where['g.msg_count'] = ['>', 0];
        // ebay账号搜索 和客服帐号搜索，优先帐号搜索，其次客服搜索；
        if (!empty($params['account_id'])) {
            $accountids = explode(',', $params['account_id']);
            $where['g.account_id'] = ['in', $accountids];
        } else if (!empty($params['customer_id'])) {  //通过客服帐号来搜索；
            // 通过客服id找到所管理ebay账号id
            $accountids = $this->getCustomerAccount($params['customer_id']);
            $where['g.account_id'] = -1;
            //通过条件搜索出来的账号id再进行处理
            if ($accountids) {
                $where['g.account_id'] = ['in', $accountids];
            }
        }

        //消息处理状态 1-未处理 2-已处理 11-超过48小时未处理
        if (!empty($params['state'])) {
            if ($params['state'] == 1) {
                $where['g.status'] = 0;
            } elseif ($params['state'] == 2) {
                $where['g.status'] = 1;
            } elseif ($params['state'] == 11) {
                $where['g.status'] = 0;
                $where['g.last_receive_time'] = ['<', time() - 3600 * 48];
            }
        }

        //标签ID；
        if (!empty($params['flag_id'])) {
            $where['e.flag_id'] = $params['flag_id'];
        }

        // 关键词搜索
        if (!empty($params['search_key']) && !empty($params['search_val'])) {
            $field_str = '';
            switch ($params['search_key']) {
                case 'sender':
                    $field_str = 'g.sender_user';
                    break;
                case 'transaction_id':
                    $field_str = 'g.last_transaction_id';
                    break;
                case 'item_id':
                    $field_str = 'g.item_id';
                    break;
                default:
                    throw new Exception('未知搜索条件类型:' . $params['search_key']);
            }

            $where[$field_str] = $params['search_val'];
        }

        // 时间搜索
        $b_time = !empty($params['receive_date_b']) ? strtotime($params['receive_date_b'] . ' 00:00:00') : '';
        $e_time = !empty($params['receive_date_e']) ? strtotime($params['receive_date_e'] . ' 23:59:59') : '';

        if ($b_time && $e_time) {
            $where['g.last_receive_time'] = ['BETWEEN', [$b_time, $e_time]];
        } elseif ($b_time) {
            $where['g.last_receive_time'] = ['>=', $b_time];
        } elseif ($e_time) {
            $where['g.last_receive_time'] = ['<=', $e_time];
        }

        // 等级搜索
        if (!empty($params['level'])) {
            $where['g.prior_level'] = $params['level'];
        }

        // 订单类型搜索
        if (!empty($params['msg_type'])) {
            switch ($params['msg_type']) {
                case 1:
                    $where['g.item_id'] = ['<>', ''];
                    break;
                case 2:
                    $where['g.last_transaction_id'] = ['<>', ''];
                    break;
                case 3:
                    $where['o.status'] = OrderStatusConst::ForDistribution;
                    break;
                case 4:
                    // $where['g.status'] = $whereMes['status'] = ['EQ',0];
                    // $where['g.send_time'] = $
                case 99:
                    $where['g.item_id'] = ['=', ''];
                default:
                    break;
            }
        }

        return $where;
    }

    /**
     * 站内信信息列表
     *
     * @param string $sender
     *            发送人
     * @param string $itemId
     *            刊登号
     * @param string $accountId
     *            账号ID
     * @param number $subject
     *            为1： subject 用message_document 代替
     * @return array
     */
    function lists($where, $field = '', $page = 1, $pageSize = 10)
    {
        $db_field = $field ? $field : 'id,message_id,sender,account_id,sender,send_to_name,replied,message_type,send_time,item_id,subject,level,flag_id,status,send_status,message_text as text,media_info,remark,remark_time,remark_uid,update_id,create_id';
        $lists = [];

        $lists = EbayMessageModel::field($db_field)->where($where)
            ->order('send_time desc')
            ->page($page, $pageSize)
            ->select();
        $news = [];
        $uids = [];
        foreach ($lists as $val) {
            $uids[] = $val['remark_uid'];
            $uids[] = $val['update_id'];
            $uids[] = $val['create_id'];
        }
        $customers = $this->getCustomerRealname($uids);
        foreach ($lists as $mvo) {
            $tmp = $mvo->toArray();
            $tmp['receive_date'] = !empty($mvo['send_time']) ? date('Y-m-d H:i:s', $mvo['send_time']) : '';
            $tmp['text'] = $this->pregText($tmp['id'], $tmp['text']);
            $tmp['text'] = html_entity_decode($tmp['text']);

            $tmp['item_img'] = '';
            if ($tmp['item_id']) {
                $tmp['item_img'] = $this->getItemImg($tmp['item_id']);
            }

            $tmp['remark_user'] = '';
            if ($tmp['remark_uid'] > 0) {
                $tmp['remark_user'] = $customers[$tmp['remark_uid']] ?? '-';
            }

            $tmp['creator'] = '';
            if ($tmp['create_id'] > 0 || $tmp['update_id'] > 0) {
                $tmp['creator'] = $customers[$tmp['create_id']] ?? $customers[$tmp['update_id']] ?? '-';
            }
            $tmp['media'] = [];
            if (!empty($tmp['media_info'])) {
                $one = json_decode($tmp['media_info'], true);
                if (isset($one['MediaURL'])) {
                    $one = [$one];
                }

                $tmp['media'] = $this->recoverImages($one);
            }
            $news[] = $tmp;
        }
        return $news;
    }


    /**
     * 恢复图片的原本象素，通过更改链接来达到目地；
     * @param $medias
     * @return mixed
     */
    public function recoverImages($medias)
    {
        if (empty($medias)) {
            return $medias;
        }
        foreach ($medias as &$val) {
            if (empty($val['MediaURL'])) {
                continue;
            }
            $path = pathinfo($val['MediaURL']);
            if (empty($path)) {
                continue;
            }
            if (strpos($path['basename'], '$_') === false) {
                continue;
            }
            $val['MediaURL'] = $path['dirname']. '/'. '$_10.'. $path['extension'];
        }
        unset($val);
        return $medias;
    }


    /**
     * 判断text符不符合条件，不符合，则重新去处理一下；
     * @param $message_id
     * @param string $text
     * @return mixed|string
     * @throws Exception
     */
    public function pregText($id, $text = '')
    {
        if ($text !== '' && ($text !== '-' && strpos($text, '@media ') === false)) {
            return $text;
        }
        $id = (int)$id;
        $bodyModel = new EbayMessageBodyModel();
        //$html = $bodyModel->where(['id' => $id])->value('message_html');
        // 正则处理html(去掉外部请求图片)
        $db = Db::connect(Config::get('mongodb'));
        $html = $db->table('ebay_message_detail')->where(['message_id' => $id])->limit(1)->value('html') ?? '';

        if (empty($html)) {
            return '';
        }
        $text = $this->extractHtml($html);
        try {
            Db::startTrans();
            // 更新body表
            $bodyModel->update(['message_document' => $text], ['id' => $id]);
            // 更新message主表
            EbayMessageModel::update([
                'message_text' => $text,
            ], [
                'id' => $id
            ]);
            Db::commit();
        } catch (Exception $ex) {
            Db::rollback();
            throw new Exception('提取发件箱回复内容错误 ' . $ex->getMessage());
        }
        return $text;
    }


    /**
     * 从邮件html里面去找出内容；
     * @param $html
     * @return mixed|string
     */
    public function extractHtml($html)
    {
        $html = preg_replace("/[\t\n\r]+/", "", $html);
        //匹配出用户内容
        preg_match('/<div id=\"UserInputtedText\"[^>]*>(.*?)<\/div>/ism', $html, $matches);
        $message_text = $matches[1] ?? '-';
        $message_text = str_replace('<br />', "\r\n", $message_text);
        return $message_text;
    }


    public function extractTransactionId($html)
    {
        $html = preg_replace("/[\t\n\r]+/", "", $html);

        //先匹配出td这一行
        preg_match('/<td class=\"product-bids\".*?>.*?<\/td>/ism', $html, $matches);
        $transaction_html = $matches[0] ?? '';

        // 第二次匹配出交易号
        preg_match('/<br>.*?<br>/ism', $transaction_html, $matches);
        $matches_2 = $matches[0] ?? '';
        if (!$matches_2) {
            return '';
        }

        $transaction_id = strip_tags($matches_2);
        $transaction_id = explode('：', $transaction_id);
        $transaction_id = isset($transaction_id[1]) ? trim($transaction_id[1]) : '';
        if (empty($transaction_id) || strlen($transaction_id) < 8 || strlen($transaction_id) > 20) {
            return '';
        }
        return $transaction_id;
    }


    /**
     * 拿取交易对应的系统单号
     * @param $transaction_id 交易ID
     * @param $item_id 项目ID
     * @param $account_id 帐号ID
     */
    public function getSystemOrder($transaction_id, $item_id, $account_id)
    {
        //返回空值
        if (empty($transaction_id)) {
            return 0;
        }
        //先找出这个交易ID对应的所有订单
        $orderIdArr = EbayOrderDetailModel::where([
            'transaction_id' => $transaction_id,
            'item_id' => $item_id
        ])->field('order_id')->column('order_id');
        if (empty($orderIdArr)) {
            return 0;
        }
        //找出系统订单
        $systemOrder_id = OrderModel::where([
            'channel_order_number' => ['in', $orderIdArr],
            'channel_id' => ChannelAccountConst::channel_ebay,
            'channel_account_id' => $account_id
        ])->field('id')->value('id');

        if (empty($systemOrder_id)) {
            return 0;
        } else {
            return $systemOrder_id;
        }
    }

    /**
     * 站内信信息列表
     *
     * @param string $sender
     *            发送人
     * @param string $itemId
     *            刊登号
     * @param string $accountId
     *            账号ID
     * @param number $subject
     *            为1： subject 用message_document 代替
     * @return array
     */
    function more_lists($params)
    {
        $page = $params['page'] ?? 1;
        $pageSize = $params['pageSize'] ?? 5;

        $where['group_id'] = $params['group_id'];

        //只显示买家来信
        $where['message_type'] = 1;
        $db_field = 'id,message_id,account_id,sender,send_to_name,message_type,replied,send_time,item_id,subject,level,flag_id,status,message_text as text,media_info';
        $lists = [];

        $result = [
            'data' => [],
            'page' => $page,
            'pageSize' => $pageSize,
            'count' => 0,
        ];

        //算出数量；
        $count = EbayMessageModel::where($where)->count();
        if (empty($count)) {
            return $result;
        }
        $result['count'] = $count;

        //limit里面+1 是为了去掉第一个已经显示出来的list;
        $lists = EbayMessageModel::field($db_field)->where($where)
            ->order('send_time desc')
            ->limit(($page - 1) * $pageSize + 1, $pageSize)
            ->select();

        if (empty($lists)) {
            return $result;
        }

        //拿取帐号
        $account = Cache::store("EbayAccount")->getTableRecord($lists[0]['account_id']);
        foreach ($lists as &$mvo) {
            $mvo['account_code'] = $account['code'] ?? '';
            if ($mvo['message_type'] == 3) {
                $mvo['title'] = $mvo['account_code'] . ' -> ' . $mvo['send_to_name'];
            } else {
                $mvo['title'] = $mvo['sender'] . ' -> ' . $mvo['account_code'];
            }
            $mvo['receive_date'] = !empty($mvo['send_time']) ? date('Y-m-d H:i:s', $mvo['send_time']) : '';
            $text = $this->pregText($mvo['id'], $mvo['text']);
            $mvo['text'] = trim(html_entity_decode($text));
        }
        $result['data'] = $lists;

        return $result;
    }


    /**
     * 通过id 获取信息
     *
     * @param string $id
     *            站内信标示id
     * @param string $field
     *            查询字段
     * @return array
     */
    function info($id, $field = '')
    {
        $field = 'id,message_id,account_id,item_id,sender,send_to_name,subject,send_time,message_type,status,send_status,message_text as text,media_info,remark,remark_time,remark_uid,create_id,update_id';
        $model = new EbayMessageModel();
        $message = $model->field($field)->where('id', $id)->find();

        if (empty($message)) {
            return [];
        }
        //更新已读状态
        $model->update(['read_status' => 1], ['id' => $id]);
        $message = $message->toArray();

        $message['receive_date'] = !empty($message['send_time']) ? date('Y-m-d H:i:s', $message['send_time']) : '';

        if ($message['message_type'] == 2) {
            $resultBody = $this->message_html($message['id']);
            $message['text'] = $resultBody['message_html'];
        } else {
            $message['text'] = $this->pregText($message['id'], $message['text']);
        }

        $message['text'] = html_entity_decode($message['text']);
        $message['item_img'] = '';
        if ($message['item_id']) {
            $message['item_img'] = $this->getItemImg($message['item_id']);
        }
        $message['media'] = [];

        $message['remark_user'] = '';
        if ($message['remark_uid'] > 0) {
            $customer = $this->getCustomerRealname($message['remark_uid']);
            $message['remark_user'] = $customer[$message['remark_uid']] ?? '-';
        }
        if (!empty($message['media_info'])) {
            $one = json_decode($message['media_info'], true);
            if (isset($one['MediaURL'])) {
                $one = [$one];
            }
            $message['media'] = $one;
        }
        $create_id = empty($message['create_id']) ? $message['update_id'] : $message['create_id'];
        $creator = $this->getCustomerRealname($create_id);
        $message['creator'] = empty($creator) ? '' : $creator[$create_id];

        return $message;
    }

    public function getCustomerRealname($uids) {
        if (!is_array($uids)) {
            $uids = [$uids];
        }

        $uids = array_values(array_unique(array_filter($uids)));
        if (empty($uids)) {
            return [];
        }
        $data = UserModel::where(['id' => ['in', $uids]])->column('realname', 'id');
        $roles = Role::alias('r')
            ->join(['role_user' => 'ru'], 'r.id=ru.role_id')
            ->where(['ru.user_id' => ['in', $uids]])
            ->field('r.name,ru.user_id')
            ->select();
        $rus = [];
        if (!empty($roles)) {
            foreach ($roles as $val) {
                if (empty($rus[$val['user_id']])) {
                    $rus[$val['user_id']] = $val['name'];
                } else {
                    $rus[$val['user_id']] .= ' '. $val['name'];
                }
            }
        }
        $newUser = [];
        foreach ($data as $user_id=>$val) {
            if (empty($rus[$user_id])) {
                $newUser[$user_id] = $rus[$user_id];
            } else {
                $newUser[$user_id] = $rus[$user_id]. ' '. $val;
            }
        }
        return $newUser;
    }

    /**
     * 通过message id 获取信息
     *
     * @param string $message_id
     *            站内信标示id
     * @param string $field
     *            查询字段
     * @return array
     */
    function message($message_id, $field = '')
    {
        $db_field = $field ? $field : 'id,message_id,sender,account_id,sender,send_to_name,replied,message_type,send_time,item_id,subject,level,flag_id,status,message_text as text,media_info';
        $message = [];
        $message = EbayMessageModel::field($db_field)->where([
            'message_id' => $message_id
        ])
            ->find()
            ->toArray();
        return $message;
    }

    /**
     * 获取商品图片
     * @param int $item_id
     * @return unknown
     */
    function getImg($item_id)
    {
        $result = EbayListingModel::field('img')->where(['item_id' => $item_id])->find();
        return $result['img'];
    }

    /**
     * 获取分组下面消息总数
     * @param int $group_id
     */
    function getGroupCount($group_id)
    {
        return EbayMessageModel::where(['group_id' => $group_id])->count();
    }

    /**
     * 通过message id 获取信息html
     *
     * @param string $message_id
     *            站内信标示id
     * @return array
     */
    function message_html($id)
    {
        $message = EbayMessageBodyModel::where([
            'id' => $id
        ])->find();
        if (empty($message)) {
            return [];
        }
        //message转数组；
        $message = $message->toArray();
        $message['message_html'] = '';

        $id = (int)$id;
        // 正则处理html(去掉外部请求图片)
        //$html = $message['message_html'];

        // 正则处理html(去掉外部请求图片)
        $db = Db::connect(Config::get('mongodb'));
        $html = $db->table('ebay_message_detail')->where(['message_id' => $id])->limit(1)->value('html') ?? '';
        //ebayhtml信息；
        if (empty($html)) {
            $message['message_html'] = $html;
            return $message;
        }

        // 匹配图片正则
        $regex = '/<img .*?>/ism';
        $matches = [];
        $html_index = [];
        if (preg_match_all($regex, $html, $matches, PREG_OFFSET_CAPTURE)) {
            $matches = param($matches, 0);
            foreach ($matches as $mvo) {
                $html_index[] = [
                    'str' => $mvo[0],
                    'count' => strlen($mvo[0]),
                    'index' => $mvo[1]
                ];
            }
        }

        // 替换图片
        if ($html_index) {
            // 反序排序数组
            $html_index = array_reverse($html_index);
            foreach ($html_index as $vo) {
                $replae_str = '<!--' . $vo['str'] . '-->';
                $html = substr_replace($html, $replae_str, $vo['index'], $vo['count']);
            }
        }

        // 匹配链接正则
        $html_href = [];
        $regex = '/<div id=\"UserInputtedText\".*?>.*?<\/div>/ism';
        $regex = '/<a href=\".*?\">/ism';
        if (preg_match_all($regex, $html, $matches, PREG_OFFSET_CAPTURE)) {
            $matches = param($matches, 0);
            foreach ($matches as $mvo) {
                $html_href[] = [
                    'str' => $mvo[0],
                    'count' => strlen($mvo[0]),
                    'index' => $mvo[1]
                ];
            }
        }
        // 替换链接
        if ($html_href) {
            // 反序排序数组
            $html_href = array_reverse($html_href);
            foreach ($html_href as $vo) {
                $replae_str = '<a href="javascript:;">';
                $html = substr_replace($html, $replae_str, $vo['index'], $vo['count']);
            }
        }

        if ($html) {
            $message['message_html'] = $html;
        }

        return $message;
    }

    /**
     * 获取ebay item_id 对应图片
     *
     * @param string $item_id
     * @throws Exception
     */
    function getItemImg($item_id)
    {
        try {
            $wh['item_id'] = [
                'eq',
                $item_id
            ];
            $info = Db::name("ebay_listing")->field('img')
                ->where($wh)
                ->find();
            return $info ? $info['img'] : '';
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * 回复列表
     *
     * @param unknown $lists
     * @return unknown
     */
    function replay_lists($lists)
    {
        $db_field = 'm.id,m.message_id,sender,account_id,sender,send_to_name,replied,send_time,item_id,subject,level,status,b.message_document';
        $ebayMessageModel = new EbayMessageModel();
        foreach ($lists as &$vo) {
            $messages = $ebayMessageModel->alias('m')
                ->join('ebay_message_body b', 'm.id = b.id')
                ->field($db_field)
                ->where([
                    'message_parent_id' => $vo['message_id']
                ])
                ->order('send_time desc')
                ->select();

            $vo['replay'] = $messages;
        }
        return $lists;
    }

    /**
     * 获取消息相关信息
     *
     * @param unknown $id
     * @return unknown
     */
    function getMsgInfo($id)
    {
        $MessageModel = new EbayMessageModel();
        $result = $MessageModel->find($id, '*');
        return $result;
    }

    /**
     * 获取消息优先级消息统计
     *
     * @return array
     */
    public function getMessageLevelCount()
    {
        $MessageModel = new EbayMessageModel();

        $ebayMessageGroupModel = new EbayMessageGroupModel();
        $result = $ebayMessageGroupModel->field('prior_level,count("id") as count')
            ->group('prior_level')
            ->select();

        foreach ($result as $key => $vo) {
            $data[$vo['prior_level']] = $vo['count'];
        }

        $levelList = array_merge(array(
            0 => '全部'
        ), $this->message_level);
        foreach ($levelList as $level_id => $val) {
            $lists[] = [
                'id' => $level_id,
                'name' => $val,
                'count_num' => isset($data[$level_id]) ? $data[$level_id] : 0
            ];
        }

        return $lists;
    }

    /**
     * @title ebay来信
     *
     * @author tanbin
     * @method GET
     * @disabled
     */
    public function getMessageList($params = [], $page = 1, $pageSize = 1)
    {
        $where = [];
        $where['is_invalid'] = [
            'NEQ',
            1
        ];
        if (empty($params['type'])) {
            return json([
                'message' => '缺少参数type'
            ], 400);
        }

        // 消息类型 message_type
        if ($params['type'] == 1) {
            $message_type = 2;
        } elseif ($params['type'] == 2) {
            $message_type = 3;
        }
        $where['message_type'] = [
            'EQ',
            $message_type
        ];

        // 关键词搜索
        if (!empty($params['keyword'])) {
            $where['subject'] = ['LIKE', '%' . $params['keyword'] . '%'];
            // 保存搜索值到
        }
        if (!empty($params['search_key']) && !empty($params['search_val']) && in_array($params['search_key'], [
                'subject',
                'send_to_name',
                'transaction_id'
            ])
        ) {
            if ($params['search_key'] == 'subject') {
                $where[$params['search_key']] = [
                    'LIKE',
                    '%' . $params['search_val'] . '%'
                ];
            } else {
                $where[$params['search_key']] = [
                    'EQ',
                    $params['search_val']
                ];
            }
        }

        // 客服账号搜索
        if (!empty($params['customer_id'])) {
            // 通过客服id找到所管理ebay账号id
            $acountids = $this->getCustomerAccount($params['customer_id']);
            if ($acountids) {
                $where['account_id'] = $whereMes['account_id'] = [
                    'in',
                    $acountids
                ];
            } else {
                $where['account_id'] = -1;
            }
        }

        // ebay 账号搜索
        if (param($params, 'account_id')) {
            $where['account_id'] = $params['account_id'];
        }
        // 消息发送状态
        if (!empty($params['send_state']) && $params['send_state'] > 0) {
            if ($params['send_state'] == 1)
                $where['send_status'] = 1;
            elseif ($params['send_state'] == 2)
                $where['send_status'] = 0;
        }

        // 时间搜索

        $b_time = !empty($params['receive_date_b']) ? strtotime($params['receive_date_b'] . ' 00:00:00') : '';
        $e_time = !empty($params['receive_date_e']) ? strtotime($params['receive_date_e'] . ' 23:59:59') : '';

        if ($b_time && $e_time) {
            $where['send_time'] = ['BETWEEN', [$b_time, $e_time]];
        } elseif ($b_time) {
            $where['send_time'] = ['EGT', $b_time];
        } elseif ($e_time) {
            $where['send_time'] = ['ELT', $e_time];
        }

        // 排序
        $sort = 'DESC';
        if (!empty($params['time_sort']) && $params['time_sort'] == 1) {
            $sort = 'ASC';
        }

        // 总数
        $count = EbayMessageModel::field('id')->where($where)->count();

        // 结果集
        $messageData = EbayMessageModel::field('id,account_id,subject,message_id,sender,send_to_name,send_time,send_status,update_id,create_id')->where($where)
            ->page($page, $pageSize)
            ->order('send_time ' . $sort)
            ->select();

        $new_array = [];
        $uids = [];
        foreach ($messageData as $val) {
            $uids[] = $val['update_id'];
            $uids[] = $val['create_id'];
        }
        $customers = $this->getCustomerRealname($uids);

        foreach ($messageData as $k => $v) {
            $data = array();
            $data['id'] = $v['id'];
            $data['account_id'] = $v['account_id'];
            $data['message_id'] = $v['message_id'];
            $data['sender'] = $v['sender'];
            $data['send_to_name'] = $v['send_to_name'];
            $data['receive_date'] = !empty($v['send_time']) ? date('Y-m-d H:i:s', $v['send_time']) : '';
            $bodyContent = EbayMessageBodyModel::get($v['message_id']);
            $data['subject'] = empty($bodyContent['message_document']) || $bodyContent['message_document'] == '-' ? $v['subject'] : $bodyContent['message_document'];
            $data['send_status'] = $v['send_status'];
            $data['creator'] = '';
            if ($v['create_id'] > 0 || $v['update_id'] > 0) {
                $data['creator'] = $customers[$v['create_id']] ?? $customers[$v['update_id']] ?? '';
            }
            $new_array[] = $data;
        }
        $result = [
            'data' => $new_array,
            'page' => $page,
            'pageSize' => $pageSize,
            'count' => $count
        ];

        return $result;
    }

    /**
     * 获取绑定ebay账号的客服列表
     */
    public function getEbayCustomer($channelId = 1)
    {
        return Cache::store('User')->getChannelCustomer($channelId);
    }

    /**
     * 获取客服在某平台所管理的"账号ID"-[店铺id/account_id]
     *
     * @param number $customerId
     *            客服id
     * @param number $channelId
     *            平台id
     */
    public function getCustomerAccount($customerId = 0, $channelId = 1)
    {
        return Cache::store('User')->getCustomerAccount($customerId, $channelId);
    }


    /**
     * 统计客服负责的ebay账号下，未处理的邮件的条数
     * @param array $data
     */
    public function CountNoReplayMsg($params)
    {

        $condition = $this->getWhere($params);
        $user_id = User::getCurrent();

        $channelId = ChannelAccountConst::channel_ebay;
        //取得本操作员所管的帐号；
        $accountHelp = new AccountService();
        $allAccount = $accountHelp->accountInfo($channelId);

        $where = [];
        $where['status'] = 0;
        $where['msg_count'] = ['>', 0];

        // 时间搜索
        $b_time = !empty($params['receive_date_b']) ? strtotime($params['receive_date_b'] . ' 00:00:00') : '';
        $e_time = !empty($params['receive_date_e']) ? strtotime($params['receive_date_e'] . ' 23:59:59') : '';

        if ($b_time && $e_time) {
            $where['last_receive_time'] = ['BETWEEN', [$b_time, $e_time]];
        } elseif ($b_time) {
            $where['last_receive_time'] = ['>=', $b_time];
        } elseif ($e_time) {
            $where['last_receive_time'] = ['<=', $e_time];
        }

        $accountIds = [];   //用来装应该显示多少帐号；
        if ($this->isAdmin() || $user_id == 0) {
            //如果全部授权了，就找出全部的ID；
            if (!empty($allAccount['account'])) {
                $accountIds = array_column($allAccount['account'], 'value');
            } else {
                return [];
            }
        } else {
            //客服ID过滤器
            $customerFilter = new Filter(EbayCustomerFilter::class, true);
            if ($customerFilter->filterIsEffective()) {
                $filterCustomers = [$user_id];
                $filterCustomers = $customerFilter->getFilterContent();
                if (is_array($filterCustomers)) {
                    $filterCustomers = array_merge(array_filter($filterCustomers));
                }

                $where['customer_id'] = ['in', $filterCustomers];

                //权限设置里的数据,里面返回的客服ID；
                foreach ($filterCustomers as $uid) {
                    $tmpIds = $this->getCustomerAccount($uid, $channelId);
                    if (is_array($tmpIds)) {
                        $accountIds = array_merge($accountIds, $tmpIds);
                    }
                }
            }

            //帐号过滤器
            $accountFilter = new Filter(EbayAccountFilter::class, true);
            if ($accountFilter->filterIsEffective()) {
                $filterAccounts = $accountFilter->getFilterContent();
                if (is_array($filterAccounts[0])) {
                    $accountIds = array_merge($accountIds, $filterAccounts[0]);
                }
            }

            //帐号过滤器
            $departmentFilter = new Filter(EbayDepartmentFilter::class, true);
            if ($departmentFilter->filterIsEffective()) {
                $filterAccounts = $departmentFilter->getFilterContent();
                if (is_array($filterAccounts)) {
                    $accountIds = array_merge($accountIds, $filterAccounts);
                }
            }
            $accountIds = array_merge(array_unique(array_filter($accountIds)));
        }

        $result = [];
        if (!empty($accountIds)) {
            $countlist = EbayMessageGroupModel::where($where)
                ->field('count(item_id) total,account_id')
                ->group('account_id')
                ->select();
            $sort = [];
            $tmp = [];
            foreach ($allAccount['account'] as $account) {
                if (!in_array($account['value'], $accountIds)) {
                    continue;
                }
                //把条数匹配进去
                $count = 0;
                if (!empty($countlist)) {
                    foreach ($countlist as $val) {
                        if ($val['account_id'] == $account['value']) {
                            $count = $val['total'];
                            break;
                        }
                    }
                }
                $sort[$account['value']] = $count;
                $tmp[$account['value']] = [
                    'code' => $account['label']
                ];
            }
            //重新排下序；
            arsort($sort);
            foreach ($sort as $id => $count) {
                $result[] = [
                    'id' => $id,
                    'code' => $tmp[$id]['code'],
                    'count' => $count,
                ];
            }
        }

        return $result;
    }


    /**
     * 获取消息优先级列表
     *
     * @return array
     */
    public function getMessageLevel()
    {
        $lists = [];
        foreach ($this->message_level as $k => $list) {
            $lists[] = [
                'id' => $k,
                'name' => $list
            ];
        }

        return $lists;
    }

    /**
     * 通过站内信获取该买家和该买家之间的所有订单
     *
     * @param array $data
     * @return string
     */
    public function getOrderList($data = [])
    {
        $ebayorderModel = new EbayOrder();
        $OrderModel = new OrderModel();
        $ebayMessageModel = new EbayMessageModel();

        $info = $ebayMessageModel->field('id,account_id,sender,send_to_name,transaction_id,local_order_id')
            ->where([
                'message_id' => $data['message_id']
            ])
            ->find();

        $where = [];
        $where['account_id'] = $info['account_id'];
        if (param($data, 'msg_type') == 3) {
            $where['buyer_user_id'] = $info['send_to_name'];
        } else {
            $where['buyer_user_id'] = $info['sender'];
        }
        $ebay_order_ids = $ebayorderModel->where($where)->column('order_id');
        if (empty($ebay_order_ids)) {
            return [];
        }
        $ebay_order_ids = array_merge(array_unique(array_filter($ebay_order_ids)));
        if (empty($ebay_order_ids)) {
            return [];
        }

        $field = "id,order_number,country_code,currency_code,order_amount,transaction_id,channel_order_number,`status`";
        $field .= ',pay_time,shipping_time';

        $orders = $OrderModel->field($field)
            ->where(['channel_id' => 1, 'channel_order_number' => ['in', $ebay_order_ids]])
            ->select();

        $result = [];
        if ($orders) {
            $local_order_ids = [];
            foreach ($orders as $key=>$vo) {
                $local_order_ids[] = $vo['id'];
            }
            $orderServers = new OrderService();

            //评价；
            $feedbacks = EbayFeedback::field('id,comment_type,order_id')->where([
                'order_id' => ['in', $local_order_ids]
            ])->column('id,comment_type', 'order_id');

            $cases = EbayCase::field('id,case_id,case_type,local_order_id')->where([
                'local_order_id' => ['in', $local_order_ids]
            ])->column('id,case_id,case_type', 'local_order_id');

            $requests = EbayRequest::field('id,request_id,request_type,status,local_order_id')->where([
                'local_order_id' => ['in', $local_order_ids]
            ])->column('id,request_id,request_type,status', 'local_order_id');

            foreach ($orders as $key => $vo) {
                $result[$key] = $vo->toArray();
                $result[$key]['order_id'] = strval($vo['id']);
                if ($vo['id'] == $info['local_order_id']) {
                    $result[$key]['trade'] = 1; // 当前交易
                }
                $result[$key]['shipping_name'] = '';
                $result[$key]['shipping_number'] = '';
                $result[$key]['arrival_time'] = '';
                $arrival_time1 = $vo['shipping_time'];
                $arrival_time2 = $vo['shipping_time'];
                //查出包裹号
                $packageList = OrderPackage::alias('p')->join(['shipping_method_detail' => 'sd'], 'p.shipping_id=sd.shipping_method_id', 'left')
                    ->join(['shipping_method' => 's'], 's.id=p.shipping_id', 'left')
                    ->where(['p.order_id' => $vo['id'], 'p.shipping_id' => ['>', 0]])
                    ->field('p.shipping_number,s.shortname,sd.earliest_days,sd.latest_days')
                    ->select();
                if (!empty($packageList)) {
                    $first_Shipping_number = '';
                    $shipping_name = '';
                    $shipping_number = '';
                    foreach ($packageList as $p => $package) {
                        $shipping_number .= $package['shipping_number'] . ',';
                        $shipping_name .= $package['shortname'] . ',';
                        //记录第一个跟踪号的包裹；
                        if (empty($first_Shipping_number)) {
                            $first_Shipping_number = $package['shipping_number'];
                        }
                        //只算第一个包裹号的到达时间；
                        if (
                            $arrival_time1 > 0 &&
                            $arrival_time1 > 0 &&
                            $first_Shipping_number === $package['shipping_number'] &&
                            !empty($first_Shipping_number)
                        ) {
                            if ($package['earliest_days'] > 0 && $package['latest_days'] > 0) {
                                $arrival_time1 += $package['earliest_days'] * 86400;
                                $arrival_time2 += $package['latest_days'] * 86400;
                            } else {
                                $arrival_time1 = 0;
                                $arrival_time2 = 0;
                            }
                        }
                    }
                    $shipping_name = rtrim($shipping_name, ',');
                    $shipping_number = rtrim($shipping_number, ',');
                    $result[$key]['shipping_name'] = $shipping_name;
                    $result[$key]['shipping_number'] = $shipping_number;

                    if ($arrival_time1 > 0 && $arrival_time2 > 0) {
                        $result[$key]['arrival_time'] = date('Y-m-d', $arrival_time1). '-'. date('Y-m-d', $arrival_time2);
                    }
                }
                //查出详情
                $result[$key]['sku'] = '';
                $result[$key]['sku_quantity'] = '';
                $details = OrderDetail::where(['order_id' => $vo['id']])->field('sku,sku_quantity')->select();
                if (!empty($details)) {
                    foreach ($details as $k=>$d) {
                        if ($k == 0) {
                            $result[$key]['sku'] = $d['sku'];
                            $result[$key]['sku_quantity'] = $d['sku_quantity'];
                        } else {
                            $result[$key]['sku'] .= ','. $d['sku'];
                            $result[$key]['sku_quantity'] .= ','. $d['sku_quantity'];
                        }
                    }
                }

                //订单状态；
                $result[$key]['status'] = $OrderModel->getStatus($vo['status']);

                //订单评价
                $feedback = $feedbacks[$vo['id']] ?? [];
                $comment_type = '';
                if (param($feedback, 'comment_type')) {
                    $comment_type = EbayFeedback::$COMMENT_TYPE[$feedback['comment_type']];
                }
                $result[$key]['feedback'] = empty($comment_type) ? '无' : $comment_type; // 评价

                //订单case
                $case = $cases[$vo['id']] ?? [];
                $result[$key]['dispute'] = ['case_id' => '无']; // 纠纷
                if (!empty($case)) {
                    $result[$key]['dispute'] = [
                        'id' => $case['id'],
                        'case_id' => $case['case_id'],
                        'dispute_type' => EbayRequest::getDisputeType($case['case_type'])
                    ];
                }

                //request
                $request = $requests[$vo['id']] ?? [];
                $result[$key]['request'] = ['request_id' => '无']; // 退换货
                if (!empty($request)) {
                    if ($request['status'] == 'CANCEL_CLOSED_FOR_COMMITMENT') {
                        $requesttype = EbayRequest::EBAY_DISPUTE_NOTPAID;
                    } else {
                        $requesttype = EbayRequest::getDisputeType($request['request_type']);
                    }
                    $result[$key]['request'] = [
                        'id' => $request['id'],
                        'request_id' => $request['request_id'],
                        'dispute_type' => $requesttype
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * 通过分组获取数据列表
     *
     * @param string $sender
     *            发送人
     * @param string $itemId
     *            刊登号
     * @param string $accountId
     *            账号ID
     * @param number $subject
     *            为1： subject 用message_document 代替
     * @return unknown[]
     */
    function getGroupDatas($where, $subject = 0)
    {
        $ebayMessageModel = new EbayMessageModel();
        $field = 'id,message_id,sender,account_id,sender,send_to_name,replied,send_time,item_id,subject,level,status';
        $messageList = $ebayMessageModel->field($field)
            ->order('send_time desc')
            ->where($where)
            ->select();

        foreach ($messageList as $mkey => $mvo) {
            $messageList[$mkey]['receive_date'] = !empty($mvo['send_time']) ? date('Y-m-d H:i:s', $mvo['send_time']) : '';
            $bodyContent = EbayMessageBodyModel::get($mvo['message_id']);
            if ($subject == 1) {
                $messageList[$mkey]['subject'] = empty($bodyContent['message_document']) ? $mvo['subject'] : $bodyContent['message_document'];
            } else {
                $messageList[$mkey]['text'] = $bodyContent['message_document'];
            }

        }

        return $messageList;
    }

    /**
     * ebay 平台 回复消息
     * @param number $accountId 账号id
     * @param array $message 回复消息
     * @return \service\ebay\NULL[]|boolean
     */
    public function replyMessage($accountId = 0, $message = [])
    {
        set_time_limit(0);
        // 这里读取缓存文件中的信息，并且发起一个或多个任务调用
        $accountList = Cache::store('EbayAccount')->getTableRecord($accountId);
        $token = $accountList['token'];
        if (empty($token)) {
            return [
                'status' => 0,
                'message' => '帐号授权资料不完整'
            ];
        }

        $config = [
            'userToken' => $token,
            'siteID' => 0,

            //开发者帐号相关信息；
            'devID' => $accountList['dev_id'],
            'appID' => $accountList['app_id'],
            'certID' => $accountList['cert_id'],
        ];

        $ebay = new EbayMessageApi($config);
        $res = $ebay->replayMessage($message);

        return $res;
    }


    /**
     * @title 加锁执行恬送站内信；
     */
    public function sendEbayMsgLockRun($data)
    {
        $lockCache = Cache::store('Lock');
        if ($lockCache->uniqueLock($data)) {
            try {
                $result = $this->sendEbayMsg($data);
            } catch (Exception $e) {
                $lockCache->unlock($data);
                throw new Exception($e->getMessage());
            }
            $lockCache->unlock($data);
            return $result;
        } else {
            throw new Exception('正在执行中，不可以重复提交');
        }
    }

    /**
     * 发送站内信 - 封装数据
     *
     * @param unknown $data
     */
    function sendEbayMsg($data = [])
    {
        if ($data['order_id']) {
            $order = OrderModel::field('id,channel_account_id as account_id,buyer_id,transaction_id')->where([
                'id' => $data['order_id']
            ])->find();
            $order_detail = OrderSourceDetail::field('channel_item_id as item_id')->where([
                'order_id' => $data['order_id']
            ])->find();

            $send_data = [
                'account_id' => $order['account_id'],
                'subject' => 'A message : ' . $order_detail['item_id'],
                'text' => $data['text'],
                'question_type' => 'General',
                'recipient_id' => $order['buyer_id'],
                'item_id' => $order_detail['item_id'],
                'transaction_id' => $order['transaction_id']
            ];
        } else {
            $send_data = [
                'account_id' => $data['account_id'],
                'subject' => 'A message : ' . $data['item_id'],
                'text' => $data['text'],
                'question_type' => 'General',
                'recipient_id' => $data['buyer_id'],
                'item_id' => $data['item_id']
            ];
        }
        return $this->sendMessage($send_data);
    }

    /**
     * @node ebay 平台 发送消息
     *
     * @param array $data
     * @throws JsonErrorException
     */
    public function sendMessage($data)
    {
        set_time_limit(0);
        // 这里读取缓存文件中的信息，并且发起一个或多个任务调用
        $accountList = Cache::store('EbayAccount')->getTableRecord($data['account_id']);
        $token = $accountList['token'];
        $res = [
            'status' => 0,
            'message' => ''
        ];
        if (empty($token)) {
            $res['message'] = '帐号token为空';
            return $res;
        }

        $config = [
            'userToken' => $token,
            'siteID' => 0,
            'devID' => $accountList['dev_id'],
            'appID' => $accountList['app_id'],
            'certID' => $accountList['cert_id'],
        ];

        $media = [];
        $request = Request::instance();
        // 保存图片
        $file = $request->post('img', '');
        if ($file) {
            throw new JsonErrorException('远程上传图片失败！由于域名链接原因，暂不提供上传图片！');
            // 上传图片到本地服务器
            $fileResult = $this->base64DecImg($file, 'upload/ebay_message/' . date('Y-m-d'), $data['account_id'] . '_' . time());

            // 上传图片到ebay服务器 （图片为线上url，或者base64二进制信息。url只接收http开头链接）
            $remoteFile = $this->uploadEbayPic($data['account_id'], $_SERVER['HTTP_HOST'] . '/' . $fileResult['filePath'], $fileResult['fileName'], 'url');//上传图片到ebay服务器

            if (!$remoteFile) {
                throw new JsonErrorException('远程上传图片失败！由于域名链接原因，暂不提供上传图片！');
            }

            if ($remoteFile) {
                //需保存的地址
                $media['FullURL'] = $remoteFile['FullURL']; // ebay服务器线上图片url

                // 组装回复信息
                $data['mediaName'] = $fileResult['fileName']; // 图片
                $data['mediaUrl'] = $remoteFile['FullURL']; // ebay服务器线上图片url
            }

            // 本地保存信息
            $media['MediaName'] = $fileResult['fileName'];
            $media['MediaURL'] = $fileResult['filePath']; // 本地保存本地路径
        }

        $ebay = new EbayMessageApi($config);
        $res = $ebay->sendMessage($data);
        if ($res['status'] == 1) {
            // 添加一条回复消息
            $data['seller_id'] = $accountList['account_name'];
            $mid = $this->addMsg($data, $media);
            $res['mid'] = $mid;
            return $res;
        }
        return $res;
    }

    /**
     */
    function addMsg($data = [], $media = '')
    {
        // 添加一条回复消息
        $messageId = 'E' . date('YmdHi') . rand(100, 999); // 发件箱messageId
        $request = Request::instance();
        $userInfo = Common::getUserInfo($request);

        $groupModel = new EbayMessageGroupModel();
        $messageModel = new EbayMessageModel();
        $time = time();
        //检查分组；
        $group_id = 0;
        $group = [];
        if (!empty($data['group_id'])) {
            $group = $groupModel->where(['id' => $data['group_id']])->find();
        } else {
            $group = $groupModel->where([
                'sender_user' => $data['recipient_id'],
                'receive_user' => $data['seller_id'],
                'item_id' => $data['item_id'],
                'account_id' => $data['account_id'],
            ])->find();
        }

        //经检查没有现有分组则创建，根据发件创建分组，这里必须要反过来，卖家才是收件人；
        if (empty($group)) {
            $group['account_id'] = $data['account_id'];
            $group['item_id'] = $data['item_id'];
            $group['sender_user'] = $data['recipient_id'];
            $group['receive_user'] = $data['seller_id'];

            $group['created_time'] = $time;
            $group['update_time'] = $time;
            $group['create_id'] = $userInfo['user_id'];
            $group['update_id'] = $userInfo['user_id'];
            $group['customer_id'] = $userInfo['user_id'];

            //把状态标记为已处理；
            $group['status'] = 1;

            //分组不存在，创建新的分组；
            $group_id = $groupModel->insertGetId($group);
            if (empty($group_id)) {
                throw new Exception('发送站内信时，新增分组失败');
            }
        } else {
            //更新站内信的回复状态；
            $group_id = $group['id'];
            $groupModel->update([
                'status' => 1,
                'update_time' => $time,
                'update_id' => $userInfo['user_id'],
            ], ['id' => $group['id']]);
        }

        $new_data = array();
        $new_data['group_id'] = $group_id;
        $new_data['message_id'] = $messageId;
        $new_data['message_parent_id'] = '';
        $new_data['sender'] = $data['seller_id'];
        $new_data['send_to_name'] = $data['recipient_id'];
        $new_data['read_status'] = $new_data['replied'] = $new_data['status'] = 1;
        $new_data['send_status'] = 1;
        $new_data['send_time'] = time();
        $new_data['message_type'] = 3;
        $new_data['update_id'] = $userInfo['user_id'];
        $new_data['update_time'] = $new_data['created_time'] = time();
        $new_data['account_id'] = $data['account_id'];
        $new_data['subject'] = $data['subject'];
        $new_data['item_id'] = $data['item_id'];
        $new_data['transaction_id'] = param($data, 'transaction_id');
        $new_data['item_title'] = $data['subject'];
        $new_data['message_text'] = $data['text'];

        $new_data['media_info'] = !empty($media) ? json_encode($media) : '';

        try {
            Db::startTrans();
            $mid = $messageModel->insertGetId($new_data);

            $dataBody = [];
            $dataBody['id'] = $mid;
            $dataBody['message_id'] = $messageId;
            $dataBody['message_document'] = $data['text'];
            $dataBody['created_time'] = time();
            $dataBody['create_id'] = $userInfo['user_id'];
            $dataBody['media_info'] = !empty($media) ? json_encode($media) : '';

            EbayMessageBodyModel::create($dataBody);
            Db::commit();

            return $mid;
        } catch (Exception $e) {
            Db::rollback();
            throw new Exception('保存站内信数据失败|'. $e->getMessage());
        }

        //统计数据；
        Report::statisticMessage(ChannelAccountConst::channel_ebay, $userInfo['user_id'], time(), [
            'buyer_qauntity' => 1
        ]);
    }

    /**
     * 上传图片到ebay站点服务器
     *
     * @param string $accountId 账号Id
     * @param string $file 图片url
     * @param string $fileName 图片名称
     * @param string $picType 图片类型 [ 图片链接 - url 、 Base64Binary - binary]
     */
    public function uploadEbayPic($accountId = '', $file = '', $fileName = '', $picType = '')
    {
        if (empty($accountId)) {
            throw new JsonErrorException("参数错误");
        }
        set_time_limit(0);
        // 这里读取缓存文件中的信息，并且发起一个或多个任务调用
        $account = Cache::store('EbayAccount')->getTableRecord($accountId);

        $token = $account['token'];
        if (!empty($token)) {
            $config = [
                'userToken' => $account['token'],
                'account_id' => $account['id'],
                'account_name' => $account['account_name'],

                //开发者帐号相关信息；
                'devID' => $account['dev_id'],
                'appID' => $account['app_id'],
                'certID' => $account['cert_id'],
                'siteID' => 0
            ];
            $ebay = new EbayMessageApi($config);
            $res = $ebay->uploadSitePic($file, $fileName, $picType);
            return $res;
        }

        return false;
    }


    /**
     * @title 加锁执行回复站内信；
     */
    public function replaySaveDataLockRun($messageId = '', $bodyText = '', $to_quque)
    {
        $data = [$messageId, $bodyText];
        $lockCache = Cache::store('Lock');
        $lockCache->unlock($data);
        if ($lockCache->uniqueLock($data)) {
            try {
                $result = $this->replaySaveData($messageId, $bodyText, $to_quque);
            } catch (Exception $e) {
                $lockCache->unlock($data);
                throw new Exception($e->getMessage());
            }
            $lockCache->unlock($data);
            return $result;
        } else {
            throw new Exception('正在执行中，不可以重复提交');
        }
    }

    /**
     * 回复消息保存数据处理
     *
     * @param array $data
     * @return boolean|\think\response\Json
     */
    function replaySaveData($messageId = '', $bodyText = '', $to_queue = 1)
    {
        //1.查看这条站内信存不存在；
        $ebayMessageModel = new EbayMessageModel();
        $data = $ebayMessageModel->where([
            'message_id' => $messageId
        ])->find();
        if (!$data) {
            throw new Exception('该信息不存在');
        }

        //创建 新的message_id;
        $newMessageId = 'E' . date('YmdHi') . rand(100, 999); // 发件箱messageId
        $message = [];
        $media = [];
        $time = time();


        // 保存图片
        $request = Request::instance();
        $userInfo = Common::getUserInfo($request);
        $file = $request->post('img', '');
        if ($file) {
            if ($to_queue != 1) {
                throw new Exception('发送图片站内信时，只能推送到回信队列发送');
            }
            // 上传图片到本地服务器
            $fileResult = $this->uploadCustomersFile($file, $data['account_id']);

            // 本地保存信息
            $media[0]['MediaName'] = $fileResult['fileName'];
            $media[0]['LocalUrl'] = $fileResult['filePath']; // 本地保存本地路径
        }

        $message['itemId'] = $data['item_id']; // 回复内容
        $message['parentMessageID'] = $messageId;
        $message['recipientID'] = $data['sender'];
        $message['bodyText'] = $bodyText; // 回复内容

        try {
            $new_data = array();
            $new_data['group_id'] = $data['group_id'];
            $new_data['message_id'] = $newMessageId;
            $new_data['message_parent_id'] = $data['message_id'];
            $new_data['sender'] = $data['send_to_name'];
            $new_data['send_to_name'] = $data['sender'];
            $new_data['read_status'] = $new_data['replied'] = $new_data['status'] = 0; //发送状态和回复状态；
            $new_data['send_status'] = 2; //直接标标成发送中；
            $new_data['send_time'] = $time;
            $new_data['message_type'] = 3;
            $new_data['update_id'] = $userInfo['user_id'];
            $new_data['create_id'] = $userInfo['user_id'];
            $new_data['update_time'] = $new_data['created_time'] = $time;
            $new_data['account_id'] = $data['account_id'];
            $new_data['subject'] = $data['subject'];
            $new_data['item_id'] = $data['item_id'];
            $new_data['item_title'] = $data['item_title'];
            $new_data['level'] = $data['level'];
            $new_data['message_text'] = $bodyText;
            $new_data['media_info'] = !empty($media) ? json_encode($media) : '';

            try {
                Db::startTrans();
                $msgId = $ebayMessageModel->insert($new_data, false, true, 'id');

                //保存附表数据；
                $dataBody = [];
                $dataBody['id'] = $msgId;
                $dataBody['message_id'] = $newMessageId;
                $dataBody['message_document'] = $bodyText;
                $dataBody['created_time'] = $time;
                $dataBody['create_id'] = $userInfo['user_id'];
                $dataBody['media_info'] = !empty($media) ? json_encode($media) : '';
                $ebayMessageBodyModel = new EbayMessageBodyModel();
                $ebayMessageBodyModel->allowField(true)->save($dataBody);

                //给原信息，更新一下更新时间和更新人信息；
                $ebayMessageModel->update(['update_time' => $time, 'update_id' => $userInfo['user_id']], ['id' => $data['id']]);
                //把本组信息标成处理中，但是现在暂时没有处理中的显示，所以先不标
                //EbayMessageGroupModel::update(['status' => 2, 'update_time' => $time, 'update_id' => $userInfo['user_id']], ['id' => $data['group_id']]);
                Db::commit();
            } catch (Exception $e) {
                Db::rollback();
                throw new Exception('回复信息新建失败');
            }

            //统计数据；
            Report::statisticMessage(ChannelAccountConst::channel_ebay, $userInfo['user_id'], $time, [
                'buyer_qauntity' => 1,
                'message_quantity' => 1
            ]);

            //加入发送邮件队列
            if ($to_queue) {
                if (!empty($media)) {
                    (new UniqueQueuer(EbaySendMessageQueue::class))->push($msgId, 60);
                } else {
                    (new UniqueQueuer(EbaySendMessageQueue::class))->push($msgId);
                }
                return true;
            } else {
                //开始发邮件；
                $result = $this->resendSaveData($msgId);
                if ($result['status'] == 1) {
                    return true;
                } else {
                    throw new Exception($result['message']);
                }
            }
        } catch (\Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * 重发站内信数据处理
     * @param string $messageId
     * @throws JsonErrorException
     * @return \think\response\Json
     */
    function resendSaveData($id)
    {
        $ebayMessageModel = new EbayMessageModel();
        $data = $ebayMessageModel->where(['id' => $id])->find();

        if (empty($data)) {
            throw new Exception('此信息不存在');
        }
        //已发送成功；
        if ($data['send_status'] == 1) {
            return ['status' => 1, 'message' => '站内信已发送成功'];
        }

        //找出分组并在下面更新；
        $groupModel = new EbayMessageGroupModel();
        $group = $groupModel->where(['id' => $data['group_id']])->find();

        $ebayMessageBodyModel = new EbayMessageBodyModel();
        $bodyInfo = $ebayMessageBodyModel->field('id,message_document,media_info')
            ->where(['id' => $id])
            ->find();

        $message = [];

        //先上传图片；
        if ($bodyInfo['media_info']) {
            $mediaInfo = json_decode($bodyInfo['media_info'], true);
            //有图片信息存在；
            if (!empty($mediaInfo[0]['MediaName']) && !empty($mediaInfo[0]['LocalUrl'])) {

                //上传图片到ebay服务器
                if (empty($mediaInfo[0]['MediaUrl'])) {
                    $imgbase = Cache::store('configParams')->getConfig('outerPicUrl')['value'];
                    // 上传图片到ebay服务器 （图片为线上url，或者base64二进制信息。url只接收http开头链接）
                    $remoteFile = $this->uploadEbayPic($data['account_id'], $imgbase . '/' . $mediaInfo[0]['LocalUrl'], $mediaInfo[0]['MediaName'], 'url');
                    if (!$remoteFile) {
                        $ebayMessageModel->update(['send_status' => 0], ['id' => $data['id']]);
                        throw new Exception('远程上传图片至Ebay服务器失败!');
                    } else {
                        //ebay服务器线上图片url,
                        $mediaInfo[0]['MediaUrl'] = $remoteFile['FullURL'];
                        $ebayMessageModel->update(['media_info' => json_encode($mediaInfo)], ['id' => $data['id']]);
                        $ebayMessageBodyModel->update(['media_info' => json_encode($mediaInfo)], ['id' => $bodyInfo['id']]);

                        // 组装回复信息
                        $message['mediaName'] = $mediaInfo[0]['MediaName']; // 图片
                        $message['mediaUrl'] = $mediaInfo[0]['MediaUrl']; // ebay服务器线上图片url
                    }
                } else {
                    $message['mediaName'] = $mediaInfo[0]['MediaName']; // 图片名称
                    $message['mediaUrl'] = $mediaInfo[0]['MediaUrl']; // ebay服务器url
                }
            }
        }

        $message['itemId'] = $data['item_id']; // 回复内容
        $message['parentMessageID'] = $data['message_parent_id'];
        $message['recipientID'] = $data['send_to_name'];
        $message['bodyText'] = $bodyInfo['message_document']; // 回复内容

        // 调用接口回复信息
        $replayResult = $this->replyMessage($data['account_id'], $message);

        Db::startTrans();
        try {
            $userInfo = Common::getUserInfo();
            // success - update replied 、dispose_user、dispose_time、status、send_status

            //1.更新当前发件；
            $messageUpdate = [];
            //2.更新父信处理状态；
            $parentMessageUpdate = [];
            //3.更新分组信息；
            $updateGroup = [];

            $time = time();
            //1.更新当前发件；
            if (!empty($userInfo['user_id'])) {
                $messageUpdate['update_id'] = $userInfo['user_id'];
            }
            $messageUpdate['update_time'] = $time;
            //发送成功；
            if ($replayResult['status'] === 1) {
                $messageUpdate['read_status'] = 1;
                $messageUpdate['status'] = 1;
                $messageUpdate['send_time'] = strtotime($replayResult['Timestamp']);
                $messageUpdate['send_status'] = 1;

                //父件处理状态
                $parentMessageUpdate['status'] = 1;
                $parentMessageUpdate['update_time'] = $time;
                if (empty($userInfo['user_id'])) {
                    $parentMessageUpdate['update_id'] = $userInfo['user_id'];
                }
            } else {    //发送失败；
                $messageUpdate['send_status'] = 0;
            }
            $ebayMessageModel->allowField(true)->save($messageUpdate, ['id' => $id]);

            //2.更新父信处理状态；
            if (!empty($parentMessageUpdate)) {
                $parentMessage = $ebayMessageModel->where(['message_id' => $data['message_parent_id']])->field('id, status')->find();
                $ebayMessageModel->update($parentMessageUpdate, ['id' => $parentMessage['id']]);
            }

            //3.更新分组信息；
            if (!empty($group)) {
                $updateGroup = [];
                if ($replayResult['status'] === 1) {
                    if ($group['status'] != 1) {
                        //处理邮件之前是未回复，则分组未处理数要减1；
                        if ($data['replied'] == 0 && $group['untreated_count'] > 0) {
                            $updateGroup['untreated_count']  = $group['untreated_count'] - 1;
                        }
                        //处理状态要更新为已处理；
                        $updateGroup['status'] = 1;
                        $updateGroup['update_time'] = $time;
                    }
                } else {
                    if ($group['status'] != 0 && $group['status'] != 1) {
                        $updateGroup['status'] = 0;
                        $updateGroup['update_time'] = $time;
                    }
                }
                if (!empty($updateGroup)) {
                    $groupModel->update($updateGroup, ['id' => $group['id']]);
                }
            }

            Db::commit();

            return $replayResult;
        } catch (\Exception $e) {
            Db::rollback();
            throw new Exception($e->getMessage());
        }
    }

    /**
     * 忽略站内信
     *
     * @param number $id
     * @param number $status
     * @throws JsonErrorException
     * @return boolean
     */
    function ignoreMsg($id, $status = 0)
    {
        $ebayMessageModel = new EbayMessageModel();
        $info = $ebayMessageModel->where(['id' => $id])->field('id,group_id,replied,status,send_status,replied')->find();
        if (empty($info)) {
            throw new JsonErrorException('该信息不存在');
        }
        if (in_array($info['status'], [1, 2])) {
            throw new JsonErrorException('该信息已经被处理');
        }
        //找出分组并更新；
        $groupModel = new EbayMessageGroupModel();
        $group = $groupModel->where(['id' => $info['group_id']])->field('id,untreated_count,status')->find();

        try {
            // 修改message中状态
            $update = [];
            $update['status'] = $status;

            $userInfo = Common::getUserInfo();
            $update['update_id'] = $userInfo['user_id'];
            $update['update_time'] = time();
            $update['status'] = $status;
            if (in_array($status, [1, 2])) {
                $update['replied'] = 1;
                $update['read_status'] = 1;
            }

            Db::startTrans();
            $ebayMessageModel = new EbayMessageModel();
            $ebayMessageModel->allowField(true)->save($update, ['id' => $info['id']]);

            if (!empty($group)) {
                $updateGroup = [];
                //处理邮件之前是未回复，则分组未处理数要减1；
                if ($info['replied'] == 0 && $group['untreated_count'] > 0) {
                    $updateGroup['untreated_count']  = $group['untreated_count'] - 1;
                }
                //处理状态要更新为已处理；
                $updateGroup['status'] = 1;
                $updateGroup['update_time'] = time();
                $groupModel->update($updateGroup, ['id' => $group['id']]);
            }
            Db::commit();
            return true;
        } catch (Exception $e) {
            Db::rollback();
            throw new JsonErrorException($e->getMessage());
        }
    }


    /**
     * 获取站内信匹配内容
     *
     * @param number $msg_id
     * @throws JsonErrorException
     * @return string[]|unknown[]|NULL[]
     */
    function matchFieldData($msg_id = 0)
    {
        // 查找邮件
        $message = EbayMessageModel::field('*')->where([
            'id' => $msg_id
        ])->find();
        if (empty($message)) {
            throw new JsonErrorException('站内信不存在');
        }

        // 获取卖家id
        $seller_email = '';
        if (param($message, 'account_id')) {
            $account = Cache::store('EbayAccount')->getTableRecord($message['account_id']);
            $seller_email = $account['email'];
        }

        // 获取订单数据
        $order = [];
        $order_address = [];
        if (param($message, 'transaction_id')) {
            $OrderSourceDetailModel = new OrderSourceDetail();
            $order_source_detail = $OrderSourceDetailModel->field('order_id')
                ->where([
                    'transaction_id' => $message['transaction_id']
                ])
                ->find();

            if ($order_source_detail) {
                $OrderModel = new Order();
                $orderAddressModel = new OrderAddress();
                $field = '*';
                $order = $OrderModel->field($field)
                    ->where([
                        'id' => $order_source_detail['order_id']
                    ])
                    ->find();
                if ($order) {
                    $order_address = $orderAddressModel->field($field)
                        ->where([
                            'order_id' => $order['id']
                        ])
                        ->find();
                }
            }
        }

        $data = [
            'seller_id' => $message['send_to_name'], // 卖家id
            'seller_email' => $seller_email, // 卖家email
            'buyer_id' => $message['sender'], // 买家id

            'order_id' => param($order, 'channel_order_number'), // 平台订单号
            'buyer_name' => param($order, 'buyer'), // 买家名称
            'amount' => param($order, 'order_amount'), // 订单金额
            'payment_date' => param($order, 'pay_time') ? date('Y-m-d H:i:s', $order['pay_time']) : '', // 支付时间
            'delivery_date' => param($order, 'shipping_time') ? date('Y-m-d H:i:s', $order['shipping_time']) : '', // 发货时间
            'carrier' => param($order, 'synchronize_carrier'), // 物流商
            'shipping_number' => param($order, 'synchronize_tracking_number'), // 跟踪号

            'recipient_name' => param($order_address, 'consignee'), // 收货人
            'recipient_address' => param($order_address, 'country_code') . ' ' . param($order_address, 'city') . ' ' . param($order_address, 'province') . ' ' . param($order_address, 'address')
        ] // 收货人地址

        ; // 填充匹配的字段数据

        return $data;
    }

    /**
     * 把时间切割为多个时间段；
     * @param $start
     * @param $end
     * @return array
     */
    public function makeTime($start, $end, $limit = 10)
    {
        if ($start > $end) {
            return [
                ['start' => $start, 'end' => $end]
            ];
        }

        //两断时间交互的时间；
        $all = 60;

        //间隔天数；
        $fixeTime = 86400 * $limit;    // -60秒是因为，时因最大跨度为30天，在记算时间的时候，为了方便的相交几秒种时间而设定；
        if ($fixeTime >= 86400 * 30) {
            $fixeTime = 86400 * 30 - $all;
        }
        $dateArr = [];
        //最大分割成36个月，不到3年时间；
        for ($i = 0; $i <= 61; $i++) {
            if ($i > 60) {
                throw new Exception('抓单起始时间，超过2年');
            }

            $tmp = [];
            $tmp['start'] = $start + $i * $fixeTime;
            //两次相格不到30天的时候
            if ($tmp['start'] > ($end - ($fixeTime + $all))) {
                $tmp['end'] = $end;
                $dateArr[] = $tmp;
                break;
            }
            $tmp['end'] = $start + $fixeTime * ($i + 1) + $all;
            $dateArr[] = $tmp;
        }
        return $dateArr;
    }

    /**
     * 实时取消息AskMessage
     *
     * @param array $data
     */
    public function downMemberMessage(array $data, $down_time = 0)
    {
        set_time_limit(0);

        if (empty($data)) {
            return false;
        }

        $execute_start = time(); // 执行开始时间

        //起始时间；
        $createTimeFrom = time() - 3600 * 24;
        $createTimeTo = time();

        // 上一次最后更新时间
        $last_update = Cache::store('EbayAccount')->ebayLastUpdateTime($data['account_id'], 'memberMessage');
        // 距离上次时间不能超过15天（暂时）
        if (!empty(isset($last_update['last_update_time']))) {
            if (time() - strtotime($last_update['last_update_time']) < 3600 * 24 * 15) {
                $createTimeFrom = strtotime($last_update['last_update_time']);
            } else {
                $createTimeFrom = strtotime("-15 day");
            }
        }

        //开始时间不为空
        if (!empty($down_time) && is_int($down_time)) {
            $createTimeFrom = strtotime('-' . $down_time . ' day');
        }

        //分析时间，大于10天则切开；
        $dateArr = $this->makeTime($createTimeFrom, $createTimeTo);
        $ebay = new EbayMessageApi($data);

        foreach ($dateArr as $newDate) {
            $res = $ebay->getMemberMessage($newDate['start'], $newDate['end']);
            $this->handleMemberMsgArr($res, $data['account_id'], $data['account_name']);

            $time_array = [
                'last_update_time' => date('Y-m-d H:i:s', $newDate['end'] - 7200),
                'last_download_time' => date('Y-m-d H:i:s'),
                'download_number' => count($res),
                'download_execute_time' => time() - $execute_start
            ];
            Cache::store('EbayAccount')->ebayLastUpdateTime($data['account_id'], 'memberMessage', $time_array);
        }

        return true;
    }

    /**
     * 处理memberMessage数据，转本地数据
     *
     * @param unknown $dataArr
     * @param unknown $accountId
     * @param unknown $accountName
     * @return string
     */
    function handleMemberMsgArr($dataArr, $accountId, $accountName)
    {
        if (empty($dataArr)|| !is_array($dataArr)) {
            return true;
        }

        $time = time();
        $lock = Cache::store('Lock');
        $messageModel = new EbayMessageModel();
        $bodyModel = new EbayMessageBodyModel();
        $groupModel = new EbayMessageGroupModel();

        foreach ($dataArr as $item) {
            $message_id = $item['Question']['MessageID'];
            $lockParam = ['message_id' => $message_id];

            //对message_id进行加锁，加锁成功再执行；
            if ($lock->lockParams($lockParam)) {
                $cache_msg = EbayMessageModel::field('id,group_id,replied')->where([
                    'message_id' => $message_id
                ])->find();

                //memberMessage的信息不准确，只要存在，则不需要更新；
                if (!empty($cache_msg)) {
                    $lock->unlockParams($lockParam);
                    continue;
                }

                $data = [];

                //定义发送方分类；
                if ($item['Question']['SenderID'] == 'eBay') {
                    $data['message_type'] = 2; // ebay平台 发送
                    $lock->unlockParams($lockParam);
                    continue;
                } elseif ($item['Question']['SenderID'] == $accountName) {
                    $data['message_type'] = 3; // 卖家 发送,不保存；
                    $lock->unlockParams($lockParam);
                    continue;
                } else {
                    $data['message_type'] = 1; // 买家 发送
                }

                // 信息
                $data['id'] = 0;
                $data['group_id'] = 0;
                $data['account_id'] = $accountId;
                $data['message_id'] = $item['Question']['MessageID'];
                $data['external_message_id'] = $item['Question']['MessageID'];
                $data['message_parent_id'] = 0;
                $data['sender'] = $item['Question']['SenderID'];
                $data['sender_email'] = $item['Question']['SenderEmail'] ?? '';
                $data['send_to_name'] = $item['Question']['RecipientID'];
                $data['subject'] = $item['Question']['Subject'] ?? '';

                //只保存新信息，这里全标记为未回复；
                $data['replied'] = $data['status'] = $data['read_status'] = 0;

                $data['send_time'] = strtotime($item['CreationDate']);
                $data['item_id'] = $item['Item']['ItemID'] ?? '';

                //信息内容
                $data['message_text'] = $item['Question']['Body'];

                // 图片信息
                $data['media_info'] = isset($item['Question']['MessageMedia']) ? serialize($item['Question']['MessageMedia']) : '';

                $data['update_time'] = $time;
                $data['created_time'] = $time;

                // 详细
                $detaliData = [];
                $detaliData['message_id'] = $data['message_id'];
                $detaliData['message_document'] = $data['message_text'];
                $detaliData['media_info'] = $data['media_info'];
                $detaliData['created_time'] = $time;

                try {
                    $group_id = 0;
                    $new_group = false;
                    //买家发送,且没有记据些数据时，才需要添加分组数据；
                    if ($data['message_type'] == 1) {
                        //先查找有没有分组；
                        $groupData = [];
                        $groupData['account_id'] = $data['account_id'];
                        $groupData['sender_user'] = $data['sender'];
                        $groupData['item_id'] = $data['item_id'];
                        $info = $groupModel->where($groupData)->find();

                        //分组不存在；
                        if (empty($info)) {

                            $new_group = true;

                            $groupData['receive_user'] = $data['send_to_name'];
                            $groupData['first_message_id'] = $groupData['last_message_id'] = $data['message_id'];
                            $groupData['first_receive_time'] = $data['send_time'];
                            $groupData['last_receive_time'] = $data['send_time'];

                            $groupData['untreated_count'] = ($data['replied'] == 1) ? 0 : 1;
                            $groupData['msg_count'] = 1;

                            $groupData['status'] = $data['replied'];
                            $groupData['create_id'] = 0;
                            $groupData['created_time'] = $time;
                            $groupData['update_time'] = $time;
                            //新增的时候，写进客服ID，方便查询;
                            $groupData['customer_id'] = $this->getCustomerIdByAccountId($groupData['account_id']);
                            $group_id = $groupModel->insertGetId($groupData);

                        } else {
                            $update = [];
                            //1.新的站内信进来，原分组总数量要加1
                            $update['msg_count'] = $info['msg_count'] + 1;

                            //2.未回复数量要加上新的站内住是否未处理数，可能+0，也可能+1；
                            $update['untreated_count'] = $info['untreated_count'] + (($data['replied'] == 1) ? 0 : 1);

                            //3.如果回复时间比分组第一次回复早，则把最早数据换掉；
                            if ($info['first_receive_time'] == 0 || $data['send_time'] < $info['first_receive_time']) {
                                $update['first_receive_time'] = $data['send_time'];
                                $update['first_message_id'] = $data['message_id'];
                            }
                            //4.如果回复时间比分组最后一次回复晚，则把最后一次数据换掉；！！！最重要的是按最后的这条数据的回复状态来确定是否未处理；
                            if ($data['send_time'] > $info['last_receive_time']) {
                                $update['last_receive_time'] = $data['send_time'];
                                $update['last_message_id'] = $data['message_id'];
                                $update['status'] = $data['replied'];
                            }
                            $update['update_time'] = $data['update_time'];
                            $groupModel->update($update, ['id' => $info['id']]);

                            $group_id = $info['id'];
                        }

                    }

                    Db::startTrans();
                    $data['group_id'] = $group_id;
                    $id = $messageModel->insertGetId($data);
                    $detaliData['id'] = $id;
                    $bodyModel->insert($detaliData);
                    Db::commit();

                    /*
                     * 触发买家第一封站内信事件
                     */
                    if ($new_group){
                        $this->triggerMemberMessageEvent($data);
                    }

                    $lock->unlockParams($lockParam);
                } catch (Exception $e) {
                    Db::rollback();
                    //解锁；
                    $lock->unlockParams($lockParam);
                    throw new Exception($e->getMessage(). '|'. $e->getLine());
                }
            }
        }

        return true;
    }


    public function getLeaderId()
    {
        $customer_id = 261;
        return $customer_id;
    }


    /**
     * 根据帐号ID获取客服ID；
     * @param $account_id
     */
    public function getCustomerIdByAccountId($account_id)
    {
        $customer = ChannelUserAccountMap::where([
            'account_id' => $account_id,
            'channel_id' => ChannelAccountConst::channel_ebay
        ])->find();
        if (!empty($customer) && !empty($customer['customer_id'])) {
            $customer_id = $customer['customer_id'];
        } else {
            $customer_id = $this->getLeaderId();
        }

        return $customer_id;
    }


    /**
     * 处理通知接收的
     * @param $dataArr
     * @param $accountId
     * @param $accountName
     * @return bool
     */
    public function notificationMemberMessage($dataArr, $accountId, $accountName)
    {
        $this->handleMemberMsgArr($dataArr, $accountId, $accountName);
        return true;
    }

    /**
     * 处理通知接收的
     * @param $dataArr
     * @param $accountId
     * @param $accountName
     * @return bool
     */
    public function notificationMyMessage($dataArr, $accountId, $accountName)
    {
        $this->handleMyMsgArr($dataArr, $accountId, $accountName);
        return true;
    }

    public function handleMyMsgArr($dataArr, $accountId, $accountName)
    {
        // 消息
        $ebayMessageModel = new EbayMessageModel();
        $time = time();
        $datas = [];
        foreach ($dataArr as $key => $item) {
            $message_id = $item['ExternalMessageID'] ?? $item['MessageID'];
            $SendToName = $item['SendToName'] ?? $item['RecipientUserID'];

            if ($item['Sender'] == 'eBay' || $item['Sender'] == 'csfeedback@ebay.com') {
                $message_type = 2; // ebay平台 发送
            } elseif ($item['Sender'] == $accountName) {
                $message_type = 3; // 卖家 发送
            } else {
                $message_type = 1; // 买家 发送
            }

            // *****s 从缓存 判断ebay messageid
            $cache_msg = [];
            if (empty($cache_msg)) {
                $cache_msg = $ebayMessageModel->field('id,message_id,group_id,replied,status,transaction_id,local_order_id')->where([
                    'message_id' => $message_id
                ])->find();
            }
            if ($message_type == 3 && empty($cache_msg) && !empty($item['ItemID'])) {
                $where['sender'] = $item['Sender'];
                $where['send_to_name'] = $SendToName;

                $where['send_status'] = 1;
                $where['item_id'] = $item['ItemID'];

                $tmp_time = strtotime($item['ReceiveDate']);
                $where['send_time'] = ['BETWEEN', [$tmp_time - 10, $tmp_time]];

                $cache_msg = $ebayMessageModel->field('id,message_id,group_id,replied,status,transaction_id,local_order_id')->where($where)->find();
            }

            // 信息
            $data['id'] = $cache_msg ? $cache_msg['id'] : 0;
            $data['group_id'] = $cache_msg ? $cache_msg['group_id'] : 0;
            $data['message_id'] = $message_id;
            $data['send_time'] = strtotime($item['ReceiveDate']);
            $data['expiration_time'] = strtotime($item['ExpirationDate']);

            //回复状态；
            $data['replied'] = $data['status'] = 1;
            if ($item['Replied'] == 'false' || $item['Replied'] === false) {
                $data['replied'] = $data['status'] = 0;
            }

            //回复状态,如果已存在，且是被忽略的，则标记为已读；
            if (!empty($cache_msg['status']) && $cache_msg['status'] == 2) {
                $data['replied'] = 1;
                $data['status'] = 2;
            }

            //标记已读；
            $data['read_status'] = 1;
            if ($data['replied'] == 0 && ($item['Read'] == 'false' || $item['Read'] === false)) {
                $data['read_status'] = 0;
            }

            $data['sender'] = $item['Sender'];
            $data['send_to_name'] = $SendToName;
            $data['item_id'] = $item['ItemID'] ?? '';
            $data['message_type'] = $message_type;

            $html = $item['Content'] ?? $item['Text'] ?? '';
            if (strpos($html, '<![CDATA[') !== false) {
                $html = str_replace('<![CDATA[', " ", $html);
                $html = substr($html, 0, strlen($html) - 3);
            }

            //交易ID
            if (!empty($cache_msg['transaction_id'])) {
                $data['transaction_id'] = $cache_msg['transaction_id'];
            } else {
                $data['transaction_id'] = $this->extractTransactionId($html);
            }

            //本地订单ID；
            if (!empty($cache_msg['local_order_id'])) {
                $data['local_order_id'] = $cache_msg['local_order_id'];
            } else {
                $data['local_order_id'] = $this->getSystemOrder($data['item_id'], $data['transaction_id'], $accountId);
            }

            //存在的
            if (empty($cache_msg['id']) && $message_type != 2) {
                //信息内容
                $text = $this->extractHtml($html);
                if (!empty($text)) {
                    $data['message_text'] = $text;
                }
            }

            $data['update_time'] = $time;
            if (!$data['id']) {
                $data['external_message_id'] = $item['MessageID'];
                $data['account_id'] = $accountId;
                $data['subject'] = $item['Subject'];
                $data['item_title'] = $item['ItemTitle'] ?? '';
                $data['created_time'] = $time;
            }

            // 详细
            $detaliData['message_id'] = $message_id;
            $detaliData['message_html'] = $html;
            if (!empty($data['message_text'])) {
                $detaliData['message_document'] = $data['message_text'];
            }
            $detaliData['created_time'] = $time;
            // 图片信息
            $detaliData['media_info'] = '';
            if (isset($item['MessageMedia'])) {
                $detaliData['media_info'] = json_encode($item['MessageMedia']);
            }

            $data['media_info'] = $detaliData['media_info'];

            $lists['data'] = $data;
            $lists['detaliData'] = $detaliData;

            // 分组
            $groupData = [];
            if ($message_type == 1) {
                // 买家发送才需要统计分组
                $groupData['account_id'] = $accountId;
                $groupData['msg_count'] = 1;
                $groupData['created_time'] = $time;
                $groupData['update_time'] = $time;
                $groupData['create_id'] = 0;
                $groupData['sender_user'] = $data['sender'];
                $groupData['receive_user'] = $data['send_to_name'];
                $groupData['untreated_count'] = $data['replied'] ? 0 : 1;
                $groupData['first_message_id'] = $groupData['last_message_id'] = $data['message_id'];
                $groupData['item_id'] = $data['item_id'];
                $groupData['first_receive_time'] = $data['send_time'];
                $groupData['last_receive_time'] = $data['send_time'];
                $groupData['last_transaction_id'] = $data['transaction_id'];
                $groupData['local_order_id'] = $data['local_order_id'];

                //回复了，则是处理，未回复就是未处理；
                $groupData['status'] = $data['replied'];
            }
            $lists['groupData'] = $groupData;
            $lists['downType'] = 'get_msg'; // 下载类型

            if ($message_type == 3) {
                $ebayMessageModel->addOutbox($lists);
            } else {
                $ebayMessageModel->add($lists);
            }
        }

        return true;
    }

    /**
     * 获取 get message 中数据
     *
     * @return boolean
     */
    public function getMessage()
    {
        $accountList = Cache::store('EbayAccount')->getTableRecord();
        foreach ($accountList as $k => $v) {
            $token = $v['token'];
            if ($v['download_message'] > 0 && !empty($token) && $v['is_invalid'] == 1) {
                $data = [
                    'userToken' => $token,
                    'siteID' => 0,
                    'pageNum' => 1,
                    'account_id' => $v['id'],
                    'account_name' => $v['account_name'],

                    //开发者帐号相关信息；
                    'devID' => $v['dev_id'],
                    'appID' => $v['app_id'],
                    'certID' => $v['cert_id'],
                ];

                $res = $this->downMessage($data);
                sleep(10);
            }
        }

        return true;
    }

    /**
     * 实时取消息
     *
     * @param array $data
     */
    public function downMessage(array $data, $down_time = 0)
    {
        if (empty($data)) {
            return false;
        }

        // 执行开始时间
        $execute_start = time();
        $createTimeFrom = time() - 3600 * 24;
        $createTimeTo = time();

        // 最后更新时间
        $last_update = Cache::store('EbayAccount')->ebayLastUpdateTime($data['account_id'], 'myMessage');
        // 距离上次时间不能超过5天（暂时）
        if (!empty($last_update['last_update_time'])) {
            if (time() - strtotime($last_update['last_update_time']) < 3600 * 24 * 5) {
                $createTimeFrom = strtotime($last_update['last_update_time']);
            } else {
                $createTimeFrom = strtotime("-15 day");
            }
        }

        //开始时间不为空
        if (!empty($down_time) && is_int($down_time)) {
            $createTimeFrom = strtotime('-' . $down_time . ' day');
        }

        //分析时间，大于10天则切开；
        $dateArr = $this->makeTime($createTimeFrom, $createTimeTo);
        $ebay = new EbayMessageApi($data);

        foreach ($dateArr as $newDate) {
            $total = $ebay->getMessage($data['account_id'], $data['account_name'], $newDate['start'], $newDate['end']);

            // 存入缓存. 只要远程获取了，就算没数据也更新时间。
            $newStartTime = date('Y-m-d H:i:s', $newDate['end'] - 7200);
            $time_array = [
                'last_update_time' => $newStartTime,
                'last_download_time' => date('Y-m-d H:i:s'),
                'download_number' => $total,
                'download_execute_time' => time() - $execute_start
            ];
            Cache::store('EbayAccount')->ebayLastUpdateTime($data['account_id'], 'myMessage', $time_array);
        }
        return true;
    }

    /**
     * 获取 发件箱内容 中数据
     *
     * @return boolean
     */
    public function getOutboxMessage()
    {
        $accountList = Cache::store('EbayAccount')->getTableRecord();
        foreach ($accountList as $k => $v) {
            $token = $v['token'];
            if ($v['download_message'] > 0 && !empty($token) && $v['is_invalid'] == 1) {
                $data = [
                    'userToken' => $token,
                    'siteID' => 0,
                    'pageNum' => 1,
                    'account_id' => $v['id'],
                    'account_name' => $v['account_name'],

                    //开发者帐号相关信息；
                    'devID' => $v['dev_id'],
                    'appID' => $v['app_id'],
                    'certID' => $v['cert_id'],
                ];

                $res = $this->downOutboxMessage($data);
                sleep(10);
            }
        }

        return true;
    }

    /**
     * 下载发件箱内容
     *
     * @param array $data
     */
    public function downOutboxMessage(array $data, $down_time = 0)
    {
        if (empty($data)) {
            return false;
        }
        $execute_start = time(); // 执行开始时间
        $createTimeFrom = time() - 3600 * 24;
        $createTimeTo = time();

        // 最后更新时间
        $last_update = Cache::store('EbayAccount')->ebayLastUpdateTime($data['account_id'], 'outBoxMessage');
        // 距离上次时间不能超过5天（暂时）
        if (!empty(isset($last_update['last_update_time']))) {
            if (time() - strtotime($last_update['last_update_time']) < 3600 * 24 * 5) {
                $createTimeFrom = strtotime($last_update['last_update_time']);
            } else {
                $createTimeFrom = date("Y-m-d H:i:s", strtotime("-15 day"));
            }
        }

        //开始时间不为空
        if (!empty($down_time) && is_int($down_time)) {
            $createTimeFrom = strtotime('-' . $down_time . ' day');
        }

        //分析时间，大于10天则切开；
        $dateArr = $this->makeTime($createTimeFrom, $createTimeTo);
        $ebayMessageModel = new EbayMessageModel();
        $ebay = new EbayMessageApi($data);

        foreach ($dateArr as $newDate) {
            try{
                $total = $ebay->getMessage($data['account_id'], $data['account_name'], $newDate['start'], $newDate['end'], 1);
            } catch (Exception $e) {
                throw new Exception($e->getMessage(). '|'. $e->getFile(). '|'. $e->getLine());
            }

            $newStartTime = date('Y-m-d H:i:s', $newDate['end'] - 7200);
            $time_array = [
                'last_update_time' => $newStartTime,
                'last_download_time' => date('Y-m-d H:i:s'),
                'download_number' => $total,
                'download_execute_time' => time() - $execute_start
            ];
            Cache::store('EbayAccount')->ebayLastUpdateTime($data['account_id'], 'outBoxMessage', $time_array);
        }

        return true;
    }

    /**
     * 反编译data/base64数据流并创建图片文件
     *
     * @author Lonny ciwdream@gmail.com
     * @param string $baseData data/base64数据流
     * @param string $Dir 存放图片文件目录
     * @param string $fileName 图片文件名称(不含文件后缀)
     * @return mixed 返回新创建文件路径或布尔类型
     */
    function base64DecImg($baseData, $Dir, $fileName, $online = true)
    {
        $base_path = ROOT_PATH . '/public/';
        $imgPath = $base_path . '/' . $Dir;
        try {
            if (!is_dir($imgPath) && !mkdir($imgPath, 0777, true)) {
                return false;
            }
            $expData = explode(';', $baseData);
            $postfix = explode('/', $expData[0]);
            if (strstr($postfix[0], 'image')) {
                $postfix = $postfix[1] == 'jpeg' ? 'jpg' : $postfix[1];
                $storageDir = $imgPath . '/' . $fileName . '.' . $postfix;
                $export = base64_decode(str_replace("{$expData[0]};base64,", '', $baseData));
                try {
                    file_put_contents($storageDir, $export);
                    return [
                        'fileName' => $fileName,
                        'filePath' => $Dir . '/' . $fileName . '.' . $postfix
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


    /**
     * 上传图片到线上服务器，而非本地服务器；
     * @param $file
     * @param $account_id
     * @param int $uid
     * @param string $channel_name
     * @return array
     * @throws Exception
     */
    public function uploadCustomersFile($file, $account_id, $uid = 0, $channel_name = 'ebay')
    {
        if (empty($file)) {
            throw new Exception('上传文件内容不能为空');
        }
        if (empty($uid)) {
            $userInfo = Common::getUserInfo();
            $uid = $userInfo['user_id'];
        }
        $path = 'customers/'. $channel_name. date('Ym');

        try {
            $expData = explode(';', $file);
            $postfix = explode('/', $expData[0]);

            $base64Data = str_replace('base64,', '', $expData[1]);
            $tmpData = base64_decode($base64Data);
            if ($tmpData === false || strLen($tmpData) > 1024000) {
                throw new Exception('上传文件base64格式反译错误, 或者大小超过1MB限制');
            }

            if (strpos($postfix[0], 'image') === false) {
                throw new Exception('只能上传图片文件');
            }
            $ext = ($postfix[1] == 'jpeg') ? 'jpg' : $postfix[1];
            if (!in_array($ext, ['jpg', 'gif', 'png', 'jpeg'])) {
                throw new Exception('文件格式不支持');
            }
            $fileName = date('YmdHis'). '_'. uniqid(). '_'. $account_id. '_u'. $uid;
            $info = [
                'path' => $path,
                'name' => $fileName,
                'content' => $base64Data,
                'file_ext' => $ext
            ];

            $url = Config::get('picture_upload_url') . '/upload.php';
            $strJson = Curl::curlPost($url, $info);
            $request = json_decode($strJson, true);
            if ($request && $request['status'] == 1) {
                return [
                    'fileName' => $fileName . '.' . $ext,
                    'filePath' => $path . '/' . $fileName . '.' . $ext,
                ];
            }
            throw new Exception('上传图片至远程服务器失败:'. $strJson);
        } catch (Exception $e) {
            throw new Exception($e->getMessage(). '|'. $e->getLine(). '|'. $e->getFile());
        }
    }


    /**
     * 上传图片保存
     * @param string $spu
     * @param File $file
     * @return string
     * @throws Exception
     */
    public function uploadPic($folder, File $file)
    {
        $base_path = ROOT_PATH . 'public/upload/' . $folder;

        $dir = date('Y-m-d');
        if (!is_dir($base_path . '/' . $dir) && !mkdir($base_path . '/' . $dir, 0666, true)) {
            throw new Exception('目录创建不成功');
        }
        $info = $file->validate(['ext' => 'jpg,gif,png'])->move($base_path . '/' . $dir, 'E' . date('YmdHis'), false);
        if (empty($info)) {
            throw new Exception($file->getError());
        }
        return $dir . '/' . $info->getFilename();
    }

    /*
     * handel time
     * */
    private function setTimeToEbayTime($time_str)
    {
        return gmdate("Y-m-d\TH:i:s.000\Z", strtotime($time_str));
    }

    ///**
    // * 更新EbayMessage收件内容
    // * ！！！！！！！！！已停用！！！！！！！！！
    // * @param array $ids
    // * @return bool
    // */
    //public function updateMessageText($ids)
    //{
    //    //查出要更的message_id集合
    //    $ebayMessageModel = new EbayMessageModel();
    //    $messageIdArr = $ebayMessageModel->field('id,message_id')->where(['id' => ['in', $ids]])->column('message_id', 'id');
    //    if (empty($messageIdArr)) {
    //        return false;
    //    }
    //
    //    //根据message_id找出对应的message_html;
    //    $ebayMessageBodyModel = new EbayMessageBodyModel();
    //    $res = $ebayMessageBodyModel
    //        ->field('message_id,message_html')
    //        ->where(['message_id' => ['in', $messageIdArr]])
    //        ->column('message_html', 'message_id');
    //
    //    if (empty($res)) {
    //        return false;
    //    }
    //
    //    foreach ($messageIdArr as $id => $message_id) {
    //        //对应的详情信息不存在，或为空,直接列新主表状态；
    //        if (empty($res[$message_id])) {
    //            $ebayMessageModel->update([
    //                'message_text' => '-',
    //            ], [
    //                'id' => $id
    //            ]);
    //            continue;
    //        }
    //
    //        $html = preg_replace("/[\t\n\r]+/", "", $res[$message_id]);
    //        //匹配出用户内容
    //        preg_match('/<div id=\"UserInputtedText\"[^>]*>(.*?)<\/div>/ism', $html, $matches);
    //        $message_text = $matches[1] ?? '-';
    //        $message_text = str_replace('<br />', "\r\n", $message_text);
    //
    //        //开始更新；
    //        try {
    //            Db::startTrans();
    //            // 更新body表
    //            $ebayMessageBodyModel->update(['message_document' => $message_text], ['message_id' => $message_id]);
    //            // 更新message主表
    //            $ebayMessageModel->update([
    //                'message_text' => $message_text,
    //            ], [
    //                'id' => $id
    //            ]);
    //            Db::commit();
    //        } catch (Exception $ex) {
    //            Db::rollback();
    //            \think\Log::write('提取发件箱回复内容错误 ' . $ex->getMessage());
    //        }
    //    }
    //    unset($ebayMessageModel, $ebayMessageBodyModel, $messageIdArr, $res);
    //    return true;
    //}

    ///**
    // * Ebay匹配交易号；
    // * ！！！！！！！！！已停用！！！！！！！！！
    // * @param $ids
    // * @return bool
    // */
    //public function updateTransactionId($ids)
    //{
    //    $ebayMessageModel = new EbayMessageModel();
    //    $ebayMessageBodyModel = new EbayMessageBodyModel();
    //    $res = [];
    //    $field = 'm.id, m.group_id, m.account_id, m.message_type, m.transaction_id, m.local_order_id, m.item_id, m.sender, m.send_time, m.send_to_name, m.message_id, b.message_html, b.check_transaction';
    //    if (isset($ids[0]) && !isset($ids['start'])) {
    //        //找出需要更新的附表数据；
    //        $res = $ebayMessageBodyModel->alias('b')
    //            ->join(['ebay_message' => 'm'], 'b.id=m.id')
    //            ->field($field)
    //            ->where(['m.id' => ['IN', $ids]])
    //            ->select();
    //
    //    } else {
    //        //找出需要更新的附表数据；
    //        $res = $ebayMessageBodyModel->alias('b')
    //            ->join(['ebay_message' => 'm'], 'b.id=m.id')
    //            ->field($field)
    //            ->where([
    //                'b.check_transaction' => 1,
    //                'm.id' => ['>=', $ids['start']]
    //            ])
    //            ->limit($ids['limit'])
    //            ->select();
    //    }
    //
    //    if (empty($res)) {
    //        return false;
    //    }
    //
    //    // 修改到数据库
    //    $msgGroupModel = new EbayMessageGroupModel();
    //
    //    //循环body表，在主表存在的，才更新；
    //    foreach ($res as $message_data) {
    //        //html为空，或主表记录不存在；
    //        if (empty($message_data['message_html'])) {
    //            $ebayMessageBodyModel->update(['check_transaction' => 0], ['id' => $message_data['id']]);
    //            continue;
    //        }
    //        $local_order_id = 0;
    //        if (!empty($message_data['local_order_id'])) {
    //            $local_order_id = $message_data['local_order_id'];
    //        }
    //
    //        if (!empty($message_data['transaction_id'])) {
    //            $transaction_id = $message_data['transaction_id'];
    //        } else {
    //            $transaction_id = $this->extractTransactionId($message_data['message_html']);
    //            if (!$transaction_id) {
    //                $ebayMessageBodyModel->update(['check_transaction' => 0], ['id' => $message_data['id']]);
    //                continue;
    //            }
    //        }
    //
    //        if (strlen($transaction_id) < 8 || strlen($transaction_id) > 20) {
    //            $ebayMessageBodyModel->update(['check_transaction' => 0], ['id' => $message_data['id']]);
    //            continue;
    //        }
    //
    //        # 查询本地订单ID
    //        if (empty($local_order_id)) {
    //            $local_order_id = $this->getSystemOrder($transaction_id, $message_data['item_id'], $message_data['account_id']);
    //        }
    //
    //        //找出分组；
    //        $groupWhere = [];
    //        if (empty($message_data['group_id'])) {
    //
    //            $groupWhere['item_id'] = ['EQ', $message_data['item_id']];
    //
    //            if ($message_data['message_type'] == 3) {
    //                $groupWhere['sender_user'] = ['EQ', $message_data['send_to_name']];
    //                $groupWhere['receive_user'] = ['EQ', $message_data['sender']];
    //            } else {
    //                $groupWhere['sender_user'] = ['EQ', $message_data['sender']];
    //                $groupWhere['receive_user'] = ['EQ', $message_data['send_to_name']];
    //            }
    //        } else {
    //            $groupWhere['id'] = $message_data['group_id'];
    //        }
    //        $group = $msgGroupModel->where($groupWhere)
    //            ->field('id,last_transaction_id,local_order_id,last_receive_time')
    //            ->find();
    //
    //        try {
    //            // 更新message表
    //            $messageUpdate = [];
    //            if ($message_data['transaction_id'] != $transaction_id && !empty($transaction_id)) {
    //                $messageUpdate['transaction_id'] = $transaction_id;
    //            }
    //            if ($message_data['local_order_id'] != $local_order_id && !empty($local_order_id)) {
    //                $messageUpdate['local_order_id'] = $local_order_id;
    //            }
    //            if (!empty($group) && empty($message_data['group_id'])) {
    //                $messageUpdate['group_id'] = $group['id'];
    //            }
    //            if (!empty($messageUpdate)) {
    //                $ebayMessageModel->update($messageUpdate, ['id' => $message_data['id']]);
    //            }
    //
    //            //如果分组存在，更新分组；
    //            if (!empty($group)) {
    //                $groupUpdate = [];
    //                if ($message_data['send_time'] > $group['last_receive_time']) {
    //                    if ($group['last_transaction_id'] != $transaction_id && !empty($transaction_id)) {
    //                        $groupUpdate['last_transaction_id'] = $transaction_id;
    //                    }
    //                    if ($group['local_order_id'] != $local_order_id && !empty($local_order_id)) {
    //                        $groupUpdate['local_order_id'] = $local_order_id;
    //                    }
    //                }
    //                if (!empty($groupUpdate)) {
    //                    $msgGroupModel->update($groupUpdate, ['id' => $group['id']]);
    //                }
    //            }
    //
    //            //以上运行到最后，才更新状态，即使状态不更新，也不会有太大的影响；
    //            if ($message_data['check_transaction'] != 0) {
    //                $ebayMessageBodyModel->update(['check_transaction' => 0], ['id' => $message_data['id']]);
    //            }
    //        } catch (Exception $ex) {
    //            throw new Exception('提取交易编号错误 ' . $ex->getMessage());
    //        }
    //    }
    //    unset($ebayMessageModel, $ebayMessageBodyModel, $msgGroupModel);
    //    return true;
    //}


    /**
     * 更新接收邮件的查看、是否需要回复、标志状态
     * @param $id
     * @param $params
     * @return bool
     * @throws Exception
     */
    public function updateReceivedMail($id, $params)
    {
        $messageModel = new EbayMessageModel();
        $message = $messageModel->where(['id' => $id])->find();

        if (!$message) {
            throw new Exception('站内信id不存在', 400);
        }
        $update = [];
        if (isset($params['flag_id']) && in_array($params['flag_id'], [0, 1, 2, 3, 4])) {
            $update['flag_id'] = $params['flag_id'];
        }

        if (!empty($update)) {
            $messageModel->update($update, ['id' => $id]);
            return true;
        }
        throw new Exception('标记参数为空');
    }


    /**
     * 站内信备注
     * @param $data
     * @return array
     * @throws Exception
     */
    public function messageRemark($data)
    {
        $messageModel = new EbayMessageModel();
        $message = $messageModel->where(['id' => $data['id']])->find();
        if (!$message) {
            throw new Exception('站内信id不存在', 400);
        }
        $time = time();
        //初始数据；
        $remark = [
            'remark' => '',
            'remark_time' => 0,
            'remark_uid' => 0
        ];

        if (empty($data['remark'])) {
            $messageModel->update($remark, ['id' => $data['id']]);
            return ['message' => '清除备注成功'];
        }

        $user = Common::getUserInfo();
        $remark['remark'] = $data['remark'];
        $remark['remark_time'] = $time;
        $remark['remark_uid'] = $user['user_id'];
        $messageModel->update($remark, ['id' => $data['id']]);

        $remark['remark_user'] = $user['realname'];
        $remark['message'] = '添加备注成功';
        return $remark;
    }


    public function pushQueue($name, $params, $postman, $timer = '')
    {
        $user = Common::getUserInfo();
        if (!$this->isAdmin($user['user_id'])) {
            throw new Exception('无权限使用');
        }

        $params = json_decode($params, true);
        if ($params === false) {
            return json(['message' => 'params参数错误，必须JSON格式'], 400);
        }

        try {
            if (!empty($postman)) {
                $queue = new $name($params);
                $queue->execute();
            } else {
                if ($timer === '' && is_numeric($timer)) {
                    (new UniqueQueuer($name))->push($params, $timer);
                } else {
                    (new UniqueQueuer($name))->push($params);
                }
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage(). '|'. $e->getLine(). '|'. $e->getFile());
        }

        return true;
    }


    public function testMethod($data)
    {
        $user = Common::getUserInfo();
        if (!$this->isAdmin($user['user_id'])) {
            throw new Exception('无权限使用');
        }

        if (stripos($data['method'], 'del') !== false || stripos($data['method'], 'drop') !== false) {
            throw new Exception('禁止调用含有del或drop关键字的方法');
        }

        if (stripos($data['name'], '\\service\\') === false) {
            throw new Exception('禁止调用service以外的方法');
        }

        $serv = new $data['name']();
        $method = $data['method'];
        try {
            $data = $serv->$method(
                $data['p1'] ?? '',
                $data['p2'] ?? '',
                $data['p3'] ?? '',
                $data['p4'] ?? '',
                $data['p5'] ?? '',
                $data['p6'] ?? ''
            );

        } catch (Exception $e) {
            throw new Exception($e->getMessage(). '|'. $e->getLine(). '|'. $e->getFile());
        }

        return $data;
    }


    public function moveHtmlToMongo($page, $pageSize = 1000, $max = 0)
    {
        $db = Db::connect(Config::get('mongodb'));
        $detailModel = new EbayMessageBodyModel();
        $total = 100;
        $all = $pageSize / $total;
        for ($i = 0; $i < $all; $i++) {
            $start = ($page - 1) * $pageSize + 1 + $i * $total;
            $end = ($start - 1) + $total;
            $lists = $detailModel->where(['id' => ['BETWEEN', [$start, $end]]])->field('id message_id,message_html html')->select();
            if (empty($lists)) {
                continue;
            }

            $new = [];
            foreach ($lists as $val) {
                if ($val['message_id'] > $max) {
                    continue;
                }
                $new[] = [
                    'message_id' => (int)$val['message_id'],
                    'html' => $val['html'] ?? '',
                ];
            }
            try {
                if ($new) {
                    $db->table('ebay_message_detail')->insertAll($new);
                }
            } catch (\Exception $e) {
                throw new Exception($e->getMessage());
            }
        }

        return true;
    }


    public function moveHtmlToMongo2($page, $pageSize = 1000, $max = 0)
    {
        $db = Db::connect(Config::get('mongodb'));
        $detailModel = new EbayMessageBodyModel();
        $total = 100;
        $all = $pageSize / $total;
        for ($i = 0; $i < $all; $i++) {
            $start = ($page - 1) * $pageSize + 1 + $i * $total;
            $end = ($start - 1) + $total;
            $lists = $detailModel->where(['id' => ['BETWEEN', [$start, $end]]])->field('id message_id,message_html html')->select();
            if (empty($lists)) {
                continue;
            }

            foreach ($lists as $val) {
                if ($val['message_id'] > $max) {
                    continue;
                }
                $tem = [
                    'message_id' => (int)$val['message_id'],
                    'html' => $val['html'] ?? '',
                ];
                try {
                    $db->table('ebay_message_detail')->insert($tem);
                } catch (\Exception $e) {
                }
            }
        }

        return true;
    }


    /**
     * 触发买家第一封站内信事件
     * @param $data
     */
    public function triggerMemberMessageEvent($data)
    {
        $event_name = 'E11';
        $order_data = [
            'channel_id' => ChannelAccountConst::channel_ebay,//Y 渠道id
            'account_id' => $data['account_id'],//Y 账号id
            'channel_order_number' => '',//Y 渠道订单号
            'receiver'=>$data['sender'],//消息发送人(发信时的收件人)
            'ebay_message_data'=>[
                'created_time'=>$data['created_time'], //消息本地创建时间
            ],
            //顺序不能变，检查是否重复时候要用
            //account_id,item_id,receiver,message_id联合排重
            'extra_params'=>[ //N
                'item_id'=>$data['item_id'],
                'receiver'=>$data['sender'],//消息发送人(发信时的收件人)
                'message_id'=>$data['message_id'], //消息id
            ]
        ];
        (new MsgRuleHelp())->triggerEvent($event_name, $order_data);
    }


    /**
     * 触发买家第一封站内信事件
     * @param array $data
     */
    public function triggerMyMessageEvent($data)
    {
        $channel_order_number = '';
        if (!empty($data['local_order_id'])) {
            $channel_order_number = Db::table('order')->where('id', $data['local_order_id'])->value('channel_order_number');
        }
        $event_name = 'E11';
        $order_data = [
            'channel_id' => ChannelAccountConst::channel_ebay,//Y 渠道id
            'account_id' => $data['account_id'],//Y 账号id
            'channel_order_number' => $channel_order_number,//Y 渠道订单号
            'receiver'=>$data['sender_user'], //消息发送人(发信时的收件人)
            'ebay_message_data'=>[
                'created_time'=>$data['created_time'], //消息本地创建时间
            ],
            //顺序不能变，检查是否重复时候要用
            //account_id,item_id,receiver,message_id联合排重
            'extra_params'=>[
                'item_id'=>$data['item_id'],
                'receiver'=>$data['sender_user'], //消息发送人(发信时的收件人)
                'message_id'=>$data['first_message_id'], //消息id
            ],
        ];
        (new MsgRuleHelp())->triggerEvent($event_name, $order_data);
    }
}
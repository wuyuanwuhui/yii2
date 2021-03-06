<?php
/**
 * Created by PhpStorm.
 * User: chrispaul
 * Date: 2018/4/11
 * Time: 20:00
 */

namespace app\components;

use yii;
use yii\base\Component;
use yii\helpers\StringHelper;
use yii\base\Exception;

/**
 * 渲染组件
 *
 * Class RenderComponent
 * @package app\components
 * @property $db
 * @property $gameDb
 * @property $limit
 *
 * @author YeBuFan
 */

class RenderComponent extends Component
{

    /**
     * 数据库连接
     *
     * @var db connection
     */
    protected $db;

    /**
     * 游戏数据库连接
     *
     * @var db connection
     */
    protected $gameDb;

    /**
     * 数据查询分页条数: 详见 $this->parseLimit
     *
     * @var int
     */
    public $limit = 0;

    public function init()
    {
        parent::init();

        $this->db = Yii::$app->db;;
        $this->gameDb = Yii::$app->gameDb;
    }

    /**
     * 渲染 Load: select 注意字段大小写
     *
     * @param array $queryParams
     *
     * $queryParams = [
     *
     *  'className' => '\app\models\webadmin\ChannelPackage'  // or 'model' => '\app\models\webadmin\ChannelPackage'
     *  'select' => 'id, name',   // default *
     *  'param' => ['id' => 100], // will not use formName
     *  'formName' => 'loadform', // or null
     * ];
     *
     * @param bool $return
     * @return array if $return is true
     */
    public function renderLoad(array $queryParams, $return = false)
    {
        $model = ($queryParams['className'] ?? ($queryParams['model'] ?? ''));
        if ($model == '') $this->returnOK();
        $param = $queryParams['param'] ?? Yii::$app->request->post($queryParams['formName'] ?? null);
        $row = [];

        if (!empty($param)) {
            $condition = $bindParams = [];
            while (list($key, $value) = each($param)) {
                $condition[] = "{$key} = :{$key}";
                $bindParams[":{$key}"] = $value;
            }
            // enable bind params to void sql inject , should set where like below
            // where：condition = 'id=:id',  bindParams = [':id' => $id ]
            $select = ($queryParams['select']) ?? '*';
            $row = $model::find()->select($select)->where(implode(' And ', $condition), $bindParams)->asArray()->one();
        }
        if ($return === true) return $row;
        $this->returnOK([
            'row' => $row
        ]);
    }

    /**
     * 渲染 Save
     *
     * @param array $queryParams
     * @param bool $return
     * @return mixed
     * @throws Exception
     */
    public function renderSave(array $queryParams, $return = false)
    {
        $param = Yii::$app->request->post($queryParams['formName']);

        if (!empty($param)) {
            // validate form model
            $this->validateFormModel($queryParams['formModel'], $param);
            $pk = $queryParams['className']::primaryKey();

            if ($pk != null && is_array($pk)) {
                $pk_id = $pk[0];
                // if set primary key and value then to update model.
                if (!empty($pk_id) && !empty($param[$pk_id]) ) {
                    $pk_value = $param[$pk_id];
                    $model = $queryParams['className']::find()->where("{$pk_id} = :{$pk_id}", [":{$pk_id}" => $pk_value])->one();

                    if (!$model) throw new Exception("can not find model by {$pk_id} = $pk_value");
                } // or add new record .
                else {
                    $model = new $queryParams['className'] ();
                }

                // save model .
                $model->load($param, '');
                if (!$model->save(true)) throw new Exception(json_encode($model->getErrors(), JSON_UNESCAPED_UNICODE));
                if ($return) return $model;
            }
        }
        else {
            throw new Exception("{$queryParams['formName']} params is empty !");
        }
        $this->returnOK();
    }

    /**
     * 删除模型: 物理删除 或 逻辑删除(表有is_deleted)
     *
     * 没使用 deleteAll 是因为deleteAll不会触发 EVENT_BEFORE/AFTER_DELETE
     *
     * @param $pk           string      主键名称
     * @param $className    model class 模型类
     * @return bool
     */
    public function renderDelete($pk, $className)
    {
        $pk_value = (array) (Yii::$app->request->post($pk));
        if (empty($pk_value)) return false;

        foreach ($pk_value as $id) {
            $id = (int) $id;
            if (null != ($model = $className::findOne([$pk => $id]))) {
                if ($model->hasAttribute('is_deleted')) { // set is_deleted to 1 in table
                    $model->is_deleted = 1;
                    $model->save();
                }
                else $model->delete(); // delete record from table
            }
        }
        $this->returnOK();
    }


    /**
     * 渲染 Search
     *
     * $params = [
     *  [
     *   'formName' => 'searchForm',
     *   'db' => 'gameDb',          // default Yii::app->$db
     *   'query' => $query,         // query object or sql statement
     *   'orderBy' => 'orderBy',
     *   'groupBy' => '',
     *   'parseFormWhere' => false  // if set be false will not parse Form Where condition
     *   ],
     *
     *   ......
     *  ];
     *
     * @param array $params
     * @param bool $page
     * @param bool $return if return is true data will be returned
     * @param null $callback callable or \Closure: 对象方法 or 匿名函数, 对$rows变量的回调函数
     *  $callback 参数应该使用引用传递
     *
     * @return mixed
     * @throws Exception
     */
    public function renderSearch(array $params, $page = false, $return = false, $callback = null)
    {
        $queryParams = $data = [];
        // if is single one array or multi array
        if (array_key_exists('query', $params)) $queryParams[] = $params;
        else $queryParams = $params;
        // each query
        foreach ($queryParams as $key => $qparam)
        {
            $query = $qparam['query'];
            $db = !empty($query->modelClass) ? ($query->modelClass)::getDb() : ($qparam['db'] ?? $this->db);
            $request = Yii::$app->request->post($qparam['formName'] ?? null);
            $searchForm = (array) ($request['form'] ?? $request);
            // validate formModel
            if (!empty($qparam['formModel'])) $this->validateFormModel($qparam['formModel'], $request);
            $where = $this->parseWhere($searchForm, $query);
            $offset = (int) ($searchForm['_offset'] ?? 0);
            $this->parseLimit($request);

            // if is sql
            if (is_string($query)) {
                $cmd = $db->createCommand($query)->bindValues([':where' => ' 1=1 ' ]);
                // set where: sql must be have ":where" placeholder
                if (!empty($where)) {
                    $query = str_replace(':where', $where['condition'], $query);
                    $cmd = $db->createCommand($query)->bindValues($where['params']);
                }
                $total = $db->createCommand("Select count(0) From (". $cmd->getRawSql() .") A ")->queryScalar();
                if ($page === true) $pagination = ['total' => $total, 'offset' => $offset, 'limit' => $this->limit];
                $query = $cmd->getRawSql() . " limit {$offset}, {$this->limit} ";
                $rows = $db->createCommand($query)->queryAll();
            }
            // else is instance yii\db\Query
            else {
                if (!empty($where)) $query->andWhere($where['condition'], $where['params']);
                $total = (int) $query->count(0);
                if ($page === true) {
                    $query->offset($offset);
                    $pagination = ['total' => $total, 'offset' => $offset, 'limit' => $this->limit];
                }
                $query->limit($this->limit);
                // if set order by,  group by
                $orderBy = (!empty($qparam['orderBy']) ? $qparam['orderBy'] : ($request['orderBy'] ?? ''));
                if ($orderBy) $query->orderBy($orderBy);
                if (!empty($qparam['groupBy'])) $query->groupBy($qparam['groupBy']);
                if ($query instanceof yii\db\ActiveQuery) $query->asArray();
                // search all results
                $rows = $query->all($db);
            }
            // callback to process rows, reference passed
            if ($callback !== null){
                if (is_array($callback) && is_callable($callback) ) call_user_func_array($callback, [&$rows]);
                if ($callback instanceof \Closure) $callback($rows);
            }
            $data['table' . (int) ($key + 1)] = [
                'rows' => $rows,
                'total' => $total,
                'pagination' => $pagination ?? ['total' => 0],
            ];
            // todo : export table data
        }
        if ($return) return $data;
        // render data to client
        $this->returnOK($data);
    }

    /**
     * 验证表单模型提交数据
     *
     * @param $formModel
     * @param $params
     * @return bool
     * @throws Exception
     */
    protected function validateFormModel($formModel, $params)
    {
        $model = new $formModel;
        // model validate
        $model->load($params, '');
        if ( !$model->validate()) {
            throw new Exception(json_encode($model->getFirstErrors(), JSON_UNESCAPED_UNICODE));
        }

        return true;
    }

    /**
     * 生成where条件 : condition(条件) 和 params(参数绑定) : condition = 'a=:a and b=:b' , params = [':a' => 1, ':b' => 2]
     *
     * 提交参数数组里面的 key => value：
     *
     * key 有几种情形：
     *  a. key
     *  b. key__min 最小,  key__timestamp__min 字段使用时间戳比较
     *  c. key__max 最大,  key__timestamp__max 字段使用时间戳比较
     *  d. key_value // drop down list select value
     *
     * value 有几种情形：
     *  a. *something*
     *  b. ['in', ['1', '2']]
     *
     * @param $searchForm       表单提交的参数数组
     * @param $query  yii\db\Query
     * @return array|string
     */
    protected function parseWhere($searchForm, $query)
    {
        if (empty($searchForm)) return '';
        $where = [];
        foreach($searchForm as $key=>$value)
        {
            if (empty($value)) continue;
            if (in_array($key, ['_offset', 'page', 'limit', 'orderBy', 'groupBy'])) continue;
            if (!is_array($value)) {
                $value = trim($value);
                // if value is all: continue
                if (strtolower($value) === 'all') continue;
                // like condition
                $start_like = StringHelper::startsWith($value, '*');
                $end_like = StringHelper::endsWith($value, '*');
                // min or max condition
                $start_min = StringHelper::endsWith($key, '__min');
                $end_max = StringHelper::endsWith($key, '__max');
                // end with _value: is drop list select value
                $end_value = StringHelper::endsWith($key, '_value');

                // like
                if ($start_like === true && $end_like === true) {
                    $where['condition'][$key] = " {$key} like :{$key} ";
                    $where['params'][":{$key}"] = "%" . trim($value, '*') . "%";
                }
                else if ($start_like === true) {
                    $where['condition'][$key] = " {$key} like :{$key} ";
                    $where['params'][":{$key}"] = "%" . trim($value, '*');
                }
                else if ($end_like === true) {
                    $where['condition'][$key] = " {$key} like :{$key} ";
                    $where['params'][":{$key}"] = rtrim($value, '*') . "%";
                }
                // min or max
                else if ($start_min === true){
                    $real_key = str_replace('__min', '', $key); // do not use rtrim
                    // if need datetime convert to timestamp
                    if (StringHelper::endsWith($real_key, '__timestamp')) {
                        $real_key = str_replace('__timestamp', '', $real_key);
                        $where['condition'][$key] = " {$real_key} >= :{$key} ";
                        $where['params'][":{$key}"] = strtotime($value);
                    }
                    else {
                        $where['condition'][$key] = " {$real_key} >= :{$key} ";
                        $where['params'][":{$key}"] = $value;
                    }
                }
                else if ($end_max === true){
                    $real_key = str_replace('__max', '', $key);
                    if (StringHelper::endsWith($real_key, '__timestamp')) {
                        $real_key = str_replace('__timestamp', '', $real_key);
                        $where['condition'][$key] = " {$real_key} <= :{$key} ";
                        // todo
                        $where['params'][":{$key}"] = strtotime($value . ' 23:59:59');
                    }
                    else {
                        $where['condition'][$key] = " {$real_key} <= :{$key} ";
                        $where['params'][":{$key}"] = $value;
                    }
                }
                // drop down list select value:
                else if ($end_value === true){
                    // limit: assign its value in vii config file stands for top N
                    if ($key === 'limit_value') {
                        $this->limit = (int) $value;
                    }
                    else {
                        $real_key = str_replace('_value', '', $key);
                        // less than 0
                        if ($value === 'less0') {
                            $where['condition'][$real_key] = " {$real_key} < 0 ";
                        }
                        // large than 0
                        else if ($value === 'large0') {
                            $where['condition'][$real_key] = " {$real_key} > 0 ";
                        }
                        else {
                            $where['condition'][$key] = " {$real_key} = :{$key} ";
                            $where['params'][":{$key}"] = $value;
                        }
                    }
                }
                // equal condition
                else {
                    $where['condition'][$key] = " {$key} = :{$key} ";
                    $where['params'][":{$key}"] = $value;
                }
            }
            // range condition
            else if (is_array($value) && count($value) === 2) {
                $operator = $value[0] ?? '';
                $range = $value[1] ?? '';
                if ($operator == '' || $range == '') continue;
                if (! in_array($operator, self::$allowOperator)) continue;
                $string = str_replace(' ', '', implode(',', $range));
                $where['condition'][$key] = " {$key} {$operator} ({$string}) ";
                $where['params'][":{$key}"] = implode(', ', $range);
            }
        }
        if (!empty($where['condition'])) {
            $where = $this->filterWhere($where, $query);
            $where['condition'] = implode(" And ", $where['condition'] );
        }
        return $where;
    }

    public static $allowOperator = [
        'in',
        'like',
        '='
    ];

    public static $endsReplace = [
        '__min', '__max',
        '__timestamp',
        '_value',
    ];

    /**
     * filter where condition and bound params
     *
     * @param array $where
     * @param $query
     * @return array
     */
    protected function filterWhere(array $where, $query)
    {
        $modelClass = $query->modelClass ?? '';
        if ($modelClass != '') {
            $model = new $modelClass;
            foreach ($where['condition'] as $key=>$val)
            {
                // hasProperty: case sensitive
                if (! $model->hasProperty(str_replace(static::$endsReplace, '', $key))) {
                    unset($where['condition'][$key]);
                    unset($where['params'][":{$key}"]);
                }
            }
        }

        return $where;
    }

    /**
     * 解析 limit
     *
     * @param array $request
     */
    protected function parseLimit($request = [])
    {
        $this->limit = ($this->limit !=0) ? $this->limit : ($request['limit'] ?? Yii::$app->params['defaultPageSize']);
        $this->limit = ($this->limit > Yii::$app->params['maxPageSize']) ? Yii::$app->params['maxPageSize'] : $this->limit;
    }

    /**
     * 日期格式转换成时间戳: 正则匹配若为日期则转换
     *
     * @param $value
     * @return false|int
     */
    public static function toTimeStamp($value)
    {
        // $patten = "/^\d{4}\-([1-9]|1[012])\-([1-9]|[12][0-9]|3[01])\s+([0-9]|1[0-9]|2[0-3])\:(0?[0-9]|[1-5][0-9])(\:(0?[0-9]|[1-5][0-9]))?$/";  // 2018-10-10 12:10
        $patten = "/^\d{4}[\-](0?[1-9]|1[012])[\-](0?[1-9]|[12][0-9]|3[01])(\s+(0?[0-9]|1[0-9]|2[0-3])\:(0?[0-9]|[1-5][0-9])\:(0?[0-9]|[1-5][0-9]))?$/";
        if (preg_match($patten, $value, $out)) {
            return strtotime($out[0]);
        }

        return $value;
    }

    // ----------------------------------------------------------------------------------------------------------------

    public function returnOK($data = null)
    {
        if (!is_array($data)) {
            $data = [];
        }
        $data['ok'] = true;

        $response = Yii::$app->response;
        $response->data = $data;
        $response->send();
    }

}

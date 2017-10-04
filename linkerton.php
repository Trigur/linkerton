<?php
if (!defined('BASEPATH'))
    exit('No direct script access allowed');
/*
    Модуль для разработчиков.
    Доступные функции:
    _withFields - Устанавливаем, что результат надо возвращать с подключенными дополнительными полями. Например $CI->linkerton->_withFields()->_getNext(1);
    _getMore - работает как пагинация. Шлем номер страницы категории - получаем массив со страницами.
    _getSimilar - похожие страницы. По умолчанию сортировка случайная, иначе - как установлено в категории.
    _getNext - следующие страницы после текущей.
    _getPrev - предыдущие страницы перед текущей.
    Автор модуля imageCMS:
    trigur@yandex.ru
 */
class Linkerton extends MY_Controller
{
    private $category = false;
    private $needFields = false;
    
    public function __construct()
    {
        parent::__construct();
    }


    /**
     * Инициализация категории страницы.
     *
     * @param  integer $categoryId
     * @return bool
     */

    private function _setCategory($categoryId)
    {
        if (! $categoryId) {
            return false;
        }

        if ($this->category['id'] == $categoryId){
            return true;
        }

        $this->category = $this->lib_category->get_category($categoryId);

        if ($this->category) {
            $this->category['fetch_pages'] = unserialize($this->category['fetch_pages']);

            if ($this->category['fetch_pages']) {
                $this->category['fetch_pages'][] = $this->category['id'];
            }

            return true;
        }

        return false;
    }


    /**
     * Начальное формирования запроса в бд при _getMore или _getSimilar.
     *
     * @param  object $db
     * @return null
     */

    private function _setCatQuery(&$db)
    {
        $db->select("IF(route.parent_url <> '', concat(route.parent_url, '/', route.url), route.url) as full_url, content.*", FALSE);
        $db->join('route', 'route.id = content.route_id');
        $db->where('content.post_status', 'publish');
        $db->where('content.publish_date <=', time());
        $db->where('content.lang', $this->config->item('cur_lang'));

        if ($this->category['fetch_pages']) {
            $db->where_in('content.category', $this->category['fetch_pages']);
        }
        else {
            $db->where('content.category', $this->category['id']);
        }

        $db->order_by($this->category['order_by'], $this->category['sort_order']);
    }


    /**
     * Получить больше.
     *
     * @param  integer $categoryId - id категории.
     * @param  integer $pageNum    - номер страницы. Отсчет начинается с 0.
     * @param  bool    $checkNext  - проверять, есть ли еще страницы после текущей.
     * @param  integer $indent     - отступ. Нужен в случае, если есть необходимость выделить несколько первых страниц, а остальные показывать стандартным способом.
     * @return array || false
     */

    public function _getMore($categoryId, $pageNum = 0, $checkNext = true, $indent = 0)
    {
        if ($this->_setCategory($categoryId)){
            $this->_setCatQuery($this->db);
            $result = [];
            $offset = 0;

            if ($checkNext) {
                $offset++;
            }

            if ($indent) {
                $offset += $indent;
            }

            $result['pages'] = $this->db->get('content', $this->category['per_page'] + $offset, $pageNum * $this->category['per_page'])->result_array();

            if ($indent) {
                $result['pages'] = array_slice($result['pages'], $indent);
            }

            if ($checkNext) {
                if (count($result['pages']) > $this->category['per_page']) {
                    $result = [
                        'pages' => array_slice($result['pages'], 0, $this->category['per_page']),
                        'hasMore' => true,
                    ];
                }
                else {
                    $result['hasMore'] = false;
                }
            }

            $result['pages'] = $this->_prepareResult($result['pages'], 'many');

            return $result;
        }

        return $this->_prepareResult(false);
    }


    /**
     * Похожие страницы.
     *
     * @param  integer || array $page  - id или массив с данными страницы.
     * @param  integer || false $limit - количество страниц в результате. По умолчанию устанавливается как per_page категории.
     * @param  integer $randomOrder    - сортировать в случайном порядке.
     * @return array || false
     */

    public function _getSimilar($page, $limit = false, $isRandom = true)
    {
        $page = is_array($page) ? $page : get_page((int) $page);

        if ($page && $this->_setCategory($page['category'])){
            if ($randomOrder) {
                $this->db->order_by('content.id', 'random');
            }

            $this->_setCatQuery($this->db);

            $limit = $limit ? $limit : $this->category['per_page'];

            $this->db->where('content.id !=', $page['id']);

            $result = $this->db->get('content', $limit)->result_array();

            return $this->_prepareResult($result, 'many');
        }

        return $this->_prepareResult(false);
    }


    private $prevNextSchema = [
        'prev' => [
            'asc' => [
                'sign'  => ' <=',
                'order' => 'desc'
            ],

            'desc' => [
                'sign'  => ' >=',
                'order' => 'asc'
            ],

            'random' => [
                'sign'  => ' >=',
                'order' => 'random'
            ]
        ],

        'next' => [
            'asc' => [
                'sign'  => ' >=',
                'order' => 'asc'
            ],

            'desc' => [
                'sign'  => ' <=',
                'order' => 'desc'
            ],

            'random' => [
                'sign'  => ' >=',
                'order' => 'random'
            ]
        ],
    ];


    /**
     * Обработка _getNext или _getPrev.
     *
     * @param  object  $db
     * @param  string $compareSign
     * @return null
     */

    private function _prevNextHandler($type, $page, $limit)
    {
        if ((int) $limit <= 0){
            return $this->_prepareResult(false);
        }

        $page = is_array($page) ? $page : get_page((int)$page);

        if (! $this->_setCategory($page['category'])){
            return $this->_prepareResult(false);
        }

        $compareSign = $this->prevNextSchema[$type][$this->category['sort_order']]['sign'];
        $sortOrder   = $this->prevNextSchema[$type][$this->category['sort_order']]['order'];

        $db = $this->db;

        $db->select("content.*, IF(route.parent_url <> '', concat(route.parent_url, '/', route.url), route.url) as full_url", false);
        $db->where('content.post_status', 'publish');
        $db->where('content.publish_date <=', time());
        $db->where('content.' . $this->category['order_by'] . $compareSign, $page[$this->category['order_by']]);
        $db->where('content.id !=', $page['id']);

        if ($this->category['fetch_pages']) {
            $db->where_in('content.category', $this->category['fetch_pages']);
        }
        else {
            $db->where('content.category', $this->category['id']);
        }

        $db->order_by('content.' . $this->category['order_by'], $sortOrder);
        $db->order_by('id', 'desc');
        $db->join('route', 'route.id = content.route_id');
        $db->limit($limit);

        if ($limit > 1) {
            $result = $db->get('content', $limit)->result_array();
            return $this->_prepareResult($result, 'many');
        }
        else {
            $result = $db->get('content')->row_array();
            return $this->_prepareResult($result, 'one');
        }
    }


    /**
     * Следующие страницы.
     *
     * @param  integer || array $page  - id или массив с данными страницы.
     * @param  integer $limit
     * @return false || array
     */

    public function _getNext($page, $limit = 1)
    {
        return $this->_prevNextHandler('next', $page, $limit);
    }


    /**
     * Предыдущая страница.
     *
     * @param  integer || array $page  - id или массив с данными страницы.
     * @param  integer $limit
     * @return false || array
     */

    public function _getPrev($page, $limit = 1)
    {
        return $this->_prevNextHandler('prev', $page, $limit);
    }


    /**
     * Устанавливаем, что результат надо возвращать с подключенными дополнительными полями
     *
     * @return null
     */

    public function _withFields()
    {
        $this->needFields = true;
        return $this;
    }

    /**
     * Подготовка результата перед выдачей
     *
     * @param  false || array $data - массив с данными страниц.
     * @param  string $type - many или one
     * @return false || array
     */

    private function _prepareResult($data, $type = 'many')
    {
        if (! $this->needFields) {
            return $data;
        }

        $this->needFields = false;

        if (! $data) {
            return false;
        }

        if ($type == 'one') {
            $data = [$data];
        }

        $this->load->module('cfcm');

        foreach ($data as $key => $item) {
            $data[$key] = $this->cfcm->connect_fields($item, 'page');
        }

        if ($type == 'one') {
            return $data[0];
        }

        return $data;
    }


    /*
        Установка модуля.
    */
    public function _install()
    {
        if (! $this->dx_auth->is_admin()) {
            $this->core->error_404();
        }

        $this->db->where('name', 'linkerton')->update('components', [
            'autoload' => '1',
            'enabled'  => '1',
            'in_menu'  => '0',
        ]);
    }
}

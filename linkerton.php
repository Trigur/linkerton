<?php

if (!defined('BASEPATH'))
    exit('No direct script access allowed');

/*
    Автор модуля imageCMS:
    trigur@yandex.ru


    _getMore
    _getSimilar
    _getNext
    _getPrev
 */

class Linkerton extends MY_Controller
{
	private $category = false;

	public function __construct() {
        parent::__construct();
    }

    /**
     * Инициализация категории страницы
     *
     * @param  integer $categoryId
     * @return bool
     */
    private function _init($categoryId)
    {
        if ($this->category['id'] == $categoryId){
            return true;
        }

        $this->category = getCategory($categoryId);

        if ($this->category) {
            $this->category['fetch_pages'] = unserialize($this->category['fetch_pages']);

            return true;
        }
        
        return false;
    }

    /**
     * Начальное формирования запроса в бд при _getMore или _getSimilar
     *
     * @param  object  $db
     * @return null
     */
    private function _setCatQuery(&$db)
    {
        $db->select("IF(route.parent_url <> '', concat(route.parent_url, '/', route.url), route.url) as full_url, content.*", FALSE);
        $db->join('route', 'route.id = content.route_id');

        $db->where('content.post_status', 'publish');
        $db->where('content.publish_date <=', time());
        $db->where('content.lang', $this->config->item('cur_lang'));

        if (count($this->category['fetch_pages']) > 0) {
            $this->category['fetch_pages'][] = $this->category['id'];
            $db->where_in('content.category', $this->category['fetch_pages']);
        } else {
            $db->where('content.category', $this->category['id']);
        }

        $db->order_by($this->category['order_by'], $this->category['sort_order']);
    }

    /**
     * Похожие страницы
     *
     * @param  integer $categoryId 
     * @param  integer $pageNum
     * @param  bool    $checkNext - проверять, есть ли еще страницы после текущей.
     * @param  integer $indent - отступ. Нужен в случае, если есть необходимость выделить несколько первых страниц, а остальные показывать стандартным способом.
     * @return array
     */
    public function _getMore($categoryId, $pageNum, $checkNext = true, $indent = 0)
    {
        if ($this->_init($categoryId)){
            $this->_setCatQuery($this->db);

            $result = [];

            $offset = $pageNum * $this->category['per_page'];

            if ($checkNext) {
                $offset++;
            }

            if ($indent) {
                $offset += $indent;
            }

            $result['pages'] = $this->db->get('content', $this->category['per_page'], $offset);
            
            if ($indent) {
                $result['pages'] = array_slice($result['pages'], $indent);
            }

            if ($checkNext) {
                if (count($result['pages']) > $this->category['per_page']) {
                    $result = [
                        'pages' => array_slice($result['pages'], 0, $this->category['per_page']),
                        'more' => true,
                    ];
                } else {
                    $result['more'] = false;
                }
            }

            return $result;
        }
    }

    /**
     * Похожие страницы
     *
     * @param  integer $categoryId
     * @param  integer $pageId
     * @param  integer $limit
     * @return array
     */
    public function _getSimilar($categoryId, $pageId, $limit = 4)
    {
        if ($this->_init($categoryId)){
            $this->_setCatQuery($this->db);

            $limit = $limit ? $limit : $this->category['per_page'];

            $this->db->where('content.id !=', $pageId);
            $query = $this->db->get('content', $limit);
            
            return $query->result_array();
        }
    }

    /**
     * Начальное формирования запроса в бд при _getNext или _getPrev
     *
     * @param  object  $db
     * @param  string $compareSign
     * @return null
     */
    private function _setPrevNextQuery(&$db, $compareSign)
    {
        $db->select("content.*, IF(route.parent_url <> '', concat(route.parent_url, '/', route.url), route.url) as full_url", false);
        $db->where('content.post_status', 'publish');
        $db->where('content.publish_date <=', time());

        $db->where('content.' . $this->category['order_by'] . $compareSign, $page[$this->category['order_by']]);
        $db->where('content.id !=', $page['id']);

        if (count($this->category['fetch_pages']) > 0) {
            $this->category['fetch_pages'][] = $this->category['id'];
            $db->where_in('content.category', $this->category['fetch_pages']);
        } else {
            $db->where('content.category', $this->category['id']);
        }

        $db->order_by('content.' . $this->category['order_by'], $this->category['sort_order']);
        $db->order_by('id', 'desc');
        $db->join('route', 'route.id = content.route_id');

        $db->limit(1);
    }

    /**
     * Следующая страница
     *
     * @param  integer $categoryId
     * @param  integer || array $page
     * @param  integer $limit
     * @return false || array
     */
    public function _getNext($categoryId, $page, $limit = 1) {
        if ($this->_init($categoryId)){
            $page = is_array($page) ? $page : get_page((int)$page);
            if ($page) {
                if ($this->category['sort_order'] == 'asc') {
                    $compareSign = ' >=';
                } else {
                    $compareSign = ' <=';
                }

                $this->_setPrevNextQuery($this->db, $compareSign);

                if ($limit > 1) {
                    return $this->db->get('content', $limit)->result_array();
                } else {
                    return $this->db->get('content')->row_array();
                }
            }
        }

        return false;
    }

    /**
     * Предыдущая страница
     *
     * @param  integer $categoryId
     * @param  integer || array $page
     * @param  integer $limit
     * @return false || array
     */
    public function _getPrev($categoryId, $page, $limit = 1) {
        if ($this->_init($categoryId)){
            $page = is_array($page) ? $page : get_page((int)$page);
            if ($page) {
                $sortArr = [
                    'asc' => 'desc', 
                    'desc' => 'asc'
                ];

                if ($sortArr[$this->category['sort_order']] == 'asc') {
                    $compareSign = ' >=';
                } else {
                    $compareSign = ' <=';
                }

                $this->_setPrevNextQuery($this->db, $compareSign);

                if ($limit > 1) {
                    return $this->db->get('content', $limit)->result_array();
                } else {
                    return $this->db->get('content')->row_array();
                }
            }
        }

        return false;
    }

    /*
        Установка модуля
    */
    public function _install() {
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
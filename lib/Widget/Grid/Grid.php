<?php
namespace Widget\Grid;

use Widget\AbstractWidget;
use Widget\Grid\Action\Action;
use Widget\Grid\Column\Column;
use Widget\Grid\State\State;
use Widget\Grid\Storage\AbstractStorage;
use Widget\Grid\Toolbar\Toolbar;
use Widget\Helper;
use Widget\ObserverListener;

/**
 * Class Grid
 */
class Grid extends AbstractWidget
{
    /**
     * @var Boolean
     */
    public $selection = false;

    /**
     * Конфигурационные опиции
     * @var array
     */
    protected $template =
        '<div class="grid_ct">
            <div id="{{id}}">
                {{topToolbar}}
                <table class="table table-striped table-bordered table-condensed">
                {{header}}
                {{filters}}
                {{body}}
                </table>
                {{bottomToolbar}}
                {{footer}}
            </div>
        </div>';

    /**
     * @var \Widget\Grid\Column\Column[]
     */
    protected $columns = array();

    /**
     * Хеш для быстроко поиска колонок по полю
     * @var \Widget\Grid\Column\Column[]
     */
    protected $columnsByField = array();

    /**
     * @var \Widget\Grid\Action\Action[]
     */
    protected $actions = array();

    /**
     * @var \Widget\Grid\Storage\AbstractStorage
     */
    protected $storage = null;

    /**
     * @var string
     */
    protected $baseUrl = '';

    /**
     * Delimeter in url
     * Available ? /
     *
     * @example ? controller/?order=eyJtZW51X2lkIjoiZGVzYyJ9
     *          / controller/order/eyJtZW51X2lkIjoiZGVzYyJ9/
     * @var string
     */
    protected $uriDelimeter = '&';

    /**
     * @var bool
     */
    protected $allowMultipleOrdering = false;

    /**
     * @var Toolbar\Toolbar
     */
    protected $topToolbar = null;

    /**
     * @var Toolbar\Toolbar
     */
    protected $bottomToolbar = null;

    /**
     * Параметры урл
     * @var array
     */
    protected $urlParams = array();

    /**
     * Параметры, которые передаються в грид и участвуют в УРЛ
     * Для каждой пары ключ/значения вызываеться метод set
     *
     * @var array
     */
    protected $params = array();

    //------------------------------------------------------------------------------------------------------------------

    /**
     * @var \Widget\Grid\State\State
     */
    protected $state = null;

    /**
     * Сохранять/не сохранять состояние
     * @var Boolean
     */
    protected $saveState = true;

    //------------------------------------------------------------------------------------------------------------------

    /**
     * Заменять урл а адресной строке на урл таблици
     * @var Boolean
     */
    protected $replaceUrl = true;

    /**
     * Подгружать данные автоматически после рендеринга таблицы
     * @var Boolean
     */
    protected $autoLoad = false;

    /**
     * {@inheritdoc}
     */
    public function __construct()
    {
        //конструктор
        parent::__construct();

        //доп. данные
        $this->setParams($this->getUrlParams('params', true));

        //параметры
        $this->initGrid();
    }

    /**
     * Получить параметры УРЛ
     *
     * @param string|null $name
     * @param boolean     $encode
     *
     * @return string
     */
    public function getUrlParams($name = null, $encode = false)
    {
        $params = $_REQUEST;
        if ($name === null) {
            return $params;
        }
        $value = !empty($params[$name]) ? $params[$name] : '';

        return $encode === true ? Helper::getParam($value) : $value;
    }

    /**
     * ?????????? ?????????
     * @return Grid
     */
    public function applyState()
    {
        $params = $this->getUrlParams();
        $state = $this->getState()->getState();
        if ($this->isSaveState()) {
            if (empty($params['order']) && empty($params['filter'])) {
                if (!empty($state['filters']) && $this->getStorage()) {
                    $this->applyFilter($state['filters']);
                }

                if (!empty($state['orders']) && $storage = $this->getStorage()) {
                    $this->applyOrder($state['orders']);
                }
                if (!empty($state['page']) && $storage = $this->getStorage()) {
                    $storage->setPage((int) $state['page']);
                }
            }
        }

        if (!empty($state['columns'])) {
            foreach ($state['columns'] as $name => $column) {
                foreach ($column as $key => $value) {
                    $method = 'set' . preg_replace("#_([\w])#e", "ucfirst('\\1')", ucfirst($key));
                    method_exists($this->getColumn($name), $method) && call_user_func(array($this->getColumn($name), $method), $value);
                }
            }
        }

        return $this;
    }

    /**
     * ?????????? ????????? ??? ($_GET, $_POST - ????????)
     * @return Grid
     */
    protected function initGrid()
    {
        // ????????? drag'n'drop ???? ???? ?????????? ?? ???????
        foreach ($this->getColumns() as $column) {
            if ($column instanceof \Widget\Grid\Column\Sorting) {
                $column->setHidden(!empty($order));
            }
        }

        //???/???? ???????
        $extensionColumns = $this->getUrlParams('extension-columns', false);
        if (!empty($extensionColumns['columns'])) {
            foreach ($extensionColumns['columns'] as $i => $name) {
                $name = str_replace('col-', '', $name);
                if ($column = $this->getColumn($name)) {
                    $column->setHidden(false)->setPosition($i + 1);
                }
            }
        }
        if (!empty($extensionColumns['disabled'])) {
            foreach ($extensionColumns['disabled'] as $j => $name) {
                $name = str_replace('col-', '', $name);
                if ($column = $this->getColumn($name)) {
                    $column->setHidden(true)->setPosition($j + count($extensionColumns['columns']) + 1);
                }
            }
        }
        if (!empty($extensionColumns['clear'])) {
            $i = 1;
            foreach ($this->getColumns() as $column) {
                $column->setPosition($i++)->setHidden($column->isHidden());
            }
        }

        return $this;
    }

    /**
     * @return \Widget\Grid\State\State
     */
    public function getState()
    {
        if ($this->state === null) {
            $this->state = new State(array('grid' => $this));
        }

        return $this->state;
    }

    /**
     * @return boolean
     */
    public function isSaveState()
    {
        return $this->saveState;
    }

    /**
     * @param boolean $saveState
     *
     * @return Grid
     */
    public function setSaveState($saveState)
    {
        $this->saveState = $saveState;

        return $this;
    }

    /**
     * ?????????? ??????? ? ???????
     * @param array $filter
     *
     * @return Grid
     */
    public function applyFilter($filter)
    {
        $filter = Helper::arrayMap('urldecode', $filter);
        $data = Helper::filterNotEmpty($filter);

        $grid = $this;
        $listener = new ObserverListener(function () use ($data, $grid) {
            foreach ($data as $name => $value) {
                if (($column = $this->getColumn($name)) && ($filter = $column->getFilter()) && $column->isHidden() == false) {
                    $filter->setValue($value);
                }
            }
        });
        $this->getStorage()->on('before_load', $listener);

        return $this;
    }

    /**
     * @return \Widget\Grid\Storage\AbstractStorage
     */
    public function getStorage()
    {
        return $this->storage;
    }

    /**
     * Set storage to the grid
     *
     * @param Storage\AbstractStorage $storage
     *
     * @return Grid
     */
    public function setStorage(Storage\AbstractStorage $storage)
    {
        $this->storage = $storage;

        //apply sorting
        $order = $this->getUrlParams('order', true);
        if (!empty($order)) {
            $this->applyOrder($order);
        }

        //apply filters
        $filter = $this->getUrlParams('filter', true);
        if (!empty($filter)) {
            $this->applyFilter($filter);
        }

        //инициализация состояния таблицы
        $this->applyState();

        return $this;
    }

    /**
     * ?????????? ?????????? ? ???????
     * @param array $order
     *
     * @return Grid
     */
    public function applyOrder($order)
    {
        $grid = $this;
        $listener = new ObserverListener(function ($storage) use ($order, $grid) {
            /* @var $storage AbstractStorage */

            foreach ($order as $name => &$dir) {

                //clear order before add new ordering if not allowed multisorting
                if (!$grid->isAllowMultipleOrdering()) {
                    $storage->setOrders(array());
                }

                $column = $grid->getColumnByField($name);
                if (!empty($column) && $column->isHidden() == false && $column->isSortable()) {
                    $storage->addOrder($column->getField(), $dir);
                }
            }
        });
        $this->getStorage()->on('before_load', $listener);

        return $this;
    }

    /**
     * @return \Widget\Grid\Toolbar\Toolbar
     */
    public function getTopToolbar()
    {
        return $this->topToolbar;
    }

    /**
     * @param \Widget\Grid\Toolbar\Toolbar $toolbar
     *
     * @return Grid
     */
    public function setTopToolbar(\Widget\Grid\Toolbar\Toolbar $toolbar)
    {
        $this->topToolbar = $toolbar;
        $this->topToolbar->setGrid($this);

        return $this;
    }

    /**
     * @param string $name
     *
     * @return Column
     */
    public function getColumn($name)
    {
        return !empty($this->columns[$name]) ? $this->columns[$name] : false;
    }

    /**
     * @return \Widget\Grid\Column\Column[]
     */
    public function getColumns()
    {
        return $this->columns;
    }

    /**
     * ?????????? ???????
     * @param \Widget\Grid\Column\Column[] $columns
     *
     * @return Grid
     */
    public function setColumns($columns)
    {
        foreach ($columns as $name => &$column) {
            $this->addColumn($name, $column);
        }

        return $this;
    }

    /**
     * Get column by field name
     *
     * @param string $field
     *
     * @return Column
     */
    public function getColumnByField($field)
    {
        return !empty($this->columnsByField[$field]) ? $this->columnsByField[$field] : $this->getColumn($field);
    }

    /**
     * ?????????? ???????
     * @param string $name
     * @param Column $column
     *
     * @return Grid
     */
    public function addColumn($name, Column $column)
    {
        $column = $this->createColumns($name, $column);

        $this->columns[$name] = $column;
        $this->columnsByField[$column->getField()] = $column;

        return $this;
    }

    /**
     * Factory method
     * Create column
     *
     * _createColumns('category',
     *     array(
     *        'title' => '?????????',
     *        'dataIndex' => 'category.name',
     *        'sortable' => true,
     *        'width' => 200,
     *        'filter' => array(
     *            'class' => 'tree',
     *            'idField' => 'id',
     *            'titleField' => 'name',
     *            'type' => 'integer',
     *            'field' => 'p.parent_id',
     *        )
     *     )
     * )
     *
     * @param string $name
     * @param Column $column
     *
     * @return array
     */
    public function createColumns($name, Column $column)
    {
        //set grid
        $column->setGrid($this);

        $column->setName($name);

        //position
        if (!$column->getPosition()) {
            $column->setPosition(count($this->columns) + 1);
        }

        return $column;
    }

    /**
     * @param string $name
     *
     * @return Grid
     */
    public function removeColumn($name)
    {
        unset($this->columns[$name]);

        return $this;
    }

    /**
     * ??????? ???????
     * @param Integer $position
     * @param string  $name
     * @param Column  $column
     *
     * @return Grid
     */
    public function insert($position, $name, Column $column)
    {
        $column = $this->createColumns($name, $column);

        if ($position == count($this->columns)) {
            $this->addColumn($name, $column);
        } else {
            $index = 1;
            $columns = array();
            foreach ($this->columns as $existName => $exist) {
                if ($index == $position) {
                    $columns[$name] = $column;
                }
                $columns[$existName] = $exist;
                $index++;
            }
            $this->columns = $columns;
        }

        return $this;
    }

    /**
     * ??????? ????????
     * @return Grid
     */
    public function clearFilter()
    {
        foreach ($this->getColumns() as $column) {
            if (($filter = $column->getFilter())) {
                $filter->setValue(null);
            }
        }

        return $this;
    }

    /**
     * @return string
     */
    public function getUriDelimeter()
    {
        return $this->uriDelimeter;
    }

    /**
     * @param string $uriDelimeter
     *
     * @return Grid
     */
    public function setUriDelimeter($uriDelimeter)
    {
        $this->uriDelimeter = $uriDelimeter;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function initialHtml()
    {
        $this->reorderColumns();

        $rendered = array(
            'id' => $this->name,
            'header' => $this->renderHeader(),
            'filters' => $this->renderFilters(),
            'body' => $this->renderBody(),
            'footer' => $this->renderFooter(),
        );

        if ($topToolbar = $this->getTopToolbar()) {
            $rendered['topToolbar'] = $topToolbar->render();
        }
        if ($bToolbar = $this->getBottomToolbar()) {
            $rendered['bottomToolbar'] = $bToolbar->render();
        }

        $html = $this->getTemplate();
        foreach ($rendered as $key => $value) {
            $html = str_replace('{{' . $key . '}}', $value, $html);
        }

        //json configuration
        $json = json_encode(array(
            'id' => $this->name,
            'url' => $this->getUrl(array('page' => true)),
            'baseUrl' => $this->getBaseUrl(),
            'replaceUrl' => $this->isReplaceUrl(),
            'autoLoad' => $this->isAutoLoad(),
            'uriDelimeter' => $this->uriDelimeter
        ));
        $js = 'var ' . $this->getJavascriptObject() . ';
             $(function () {
                if (typeof(' . $this->getJavascriptObject() . ') == "undefined") {
                    ' . $this->getJavascriptObject() . ' = new Widget.Grid(' . $json . ');
                }' . '
             });';
        $this->getResourceManager()->addJavascript($js);

        //remove unused places
        $html = preg_replace('#{{[\w\w]+}}#', '', $html);

        return $html;
    }

    /**
     * Reorder columns by position ($column->getPosition())
     *
     * @return Grid
     */
    public function reorderColumns()
    {
        $positions = false;
        foreach ($this->getColumns() as $column) {
            $positions = $positions || $column->getPosition() > 0;
        }
        if ($positions) {
            uasort($this->columns, function ($a, $b) {
                if ($a->getPosition() == $b->getPosition()) {
                    return -1;
                }

                return ($a->getPosition() < $b->getPosition()) ? -1 : 1;
            });
        }

        return $this;
    }

    /**
     * Render grid header
     *
     * @return Grid
     */
    protected function renderHeader()
    {
        $html = '<colgroup>';
        if ($this->selection !== false) {
            $html .= '<col width="20" />';
        }
        foreach ($this->columns as &$column) {
            $class = '';
            if ($column->isHidden()) {
                continue;
            }
            $html .= '<col' . ($column->getWidth() ? ' width="' . $column->getWidth() . '"' : '') . ' ' . ($class ? ' class="' . $class . '"' : '') . '/>';
        }

        //??????? ????????
        if (!empty($this->actions)) {
            $html .= '<col style="width: ' . (count($this->actions) * 30) . 'px;" />';
        }
        $html .= '</colgroup>';

        $html .= '<tr class="headings">';
        if ($this->selection !== false) {
            $html .= '<th><span class="nobr"><input type="checkbox" data-role="check-all" value="' . $this->getStorage()->getCount() . '" onclick="' . $this->getJavascriptObject() . '.checkAll(\'selected[]\', this);"/></span></th>';
        }

        foreach ($this->columns as $name => &$column) {
            $class = '';
            if ($column->isHidden()) {
                continue;
            }
            $html .= '<th data-name="' . $column->getName() . '" ' . ($class ? ' class="' . $class . '"' : '') . '>';

            if ($column->isSortable()) {
                $class = 'no-sort';
                if (($dir = $this->getStorage()->isOrder($column->getField())) !== false) {
                    $class = 'sort-' . $dir;
                }
                $html .= '<div class="sort-block ' . $class . '">
                            <div class="s-sort-wrap">
                                <a rel="nofollow" data-toggle="tooltip" title="' . $column->getHint() . '" class="s-sort" href="' . $this->url(array('order' => array($name => 'add'))) . '" onclick="' . $this->getJavascriptObject() . '.load(this.href); return false;">' . $column->getTitle() . '<span></span></a>
                            </div>
                            ' . ($class !== 'not-sort' ? '<a rel="nofollow" href="' . $this->url(array('order' => array($name => 'remove'))) . '" ' . ($this->isAllowMultipleOrdering() ? 'class="s-close"' : '') . ' onclick="' . $this->getJavascriptObject() . '.load(this.href); return false;"></a>' : '') . '
                          </div>';
            } else {
                $html .= '<span data-toggle="tooltip" title="' . $column->getHint() . '">' . $column->getTitle() . '</span>';
            }
            $html .= '</th>';
        }

        //??????? ????????
        if (!empty($this->actions)) {
            $html .= '<th><span class="nobr"></span></th>';
        }

        $html .= '</tr>';

        return $html;
    }

    /**
     * @return string
     */
    protected function renderFilters()
    {
        $flag = false;
        $columns = $this->getColumns();
        foreach ($columns as &$column) {
            if (!$column->isHidden() && $column->getFilter() && $column->isFilterable()) {
                $flag = true;
            }
        }

        $html = '';
        if ($flag) {
            $html .= '<tr class="filter">';
            if ($this->isSelection() !== false) {
                $html .= '<td></td>';
            }

            foreach ($columns as &$column) {
                if ($column->isHidden()) {
                    continue;
                }
                if ($column->getFilter() && $column->isFilterable()) {
                    $html .= '<td><div class="filter-container">' . $column->getFilter()->render() . '</div></td>';
                } else {
                    $html .= '<td></td>';
                }
            }
            if ($this->getActions()) {
                $html .= '<td></td>';
            }
            $html .= '</tr>';
        }

        return $html;
    }

    /**
     * Render body of grid
     *
     * @return string
     */
    protected function renderBody()
    {
        $html = '';
        $data = $this->getStorage()->getData();
        if (!empty($data)) {
            foreach ($data as $i => $row) {
                $html .= $this->renderTr($row, $i);
            }
            $html .= $this->renderSummary();
        } else {
            $html .= '<tr><td colspan="' . (count($this->columns) + 2) . '" style="padding: 10px; text-align: center;">&nbsp;</td></tr>';
        }

        return $html;
    }

    /**
     * Rendering footer of the table
     *
     * @return string
     */
    protected function renderFooter()
    {
        return '';
    }

    /**
     * @return \Widget\Grid\Toolbar\Toolbar
     */
    public function getBottomToolbar()
    {
        return $this->bottomToolbar;
    }

    /**
     * ?????? ?????, ???? ?????? ??? ??????
     * @param \Widget\Grid\Toolbar\Toolbar|array $toolbar
     *
     * @return Grid
     */
    public function setBottomToolbar(\Widget\Grid\Toolbar\Toolbar $toolbar)
    {
        $this->bottomToolbar = $toolbar;
        $this->bottomToolbar->setGrid($this);

        return $this;
    }

    /**
     * @return string
     */
    public function getTemplate()
    {
        return $this->template;
    }

    /**
     * @param string $template
     */
    public function setTemplate($template)
    {
        $this->template = $template;
    }

    /**
     * ??? ? ?????? ??????????, ????????
     *
     * @param array $mixer
     *
     * @return string
     */
    public function getUrl($mixer = array())
    {
        return $this->url($mixer);
    }

    /**
     * @return string
     */
    public function getBaseUrl()
    {
        return $this->baseUrl;
    }

    /**
     * ??????? ???
     * @param string $url
     *
     * @return Grid
     */
    public function setBaseUrl($url)
    {
        $this->baseUrl = $url;

        return $this;
    }

    /**
     * @return Boolean
     */
    public function isReplaceUrl()
    {
        return $this->replaceUrl;
    }

    /**
     * ??????? ???
     * @param Boolean $replaceUrl
     *
     * @return Grid
     */
    public function setReplaceUrl($replaceUrl)
    {
        $this->replaceUrl = $replaceUrl;

        return $this;
    }

    /**
     * @return Boolean
     */
    public function isAutoLoad()
    {
        return $this->autoLoad;
    }

    /**
     * @param string $auto
     *
     * @return Grid
     */
    public function setAutoLoad($auto)
    {
        $this->autoLoad = $auto;

        return $this;
    }

    /**
     * @return string
     */
    public function getJavascriptObject()
    {
        return $this->name . 'Widget';
    }

    /**
     * Build url
     *
     * @param array $mixer
     *
     * @return string
     */
    protected function url($mixer = array())
    {
        $url = array();

        $storeOrders = $this->getStorage()->getOrders();
        $orders = array();
        foreach ($storeOrders as $name => $dir) {
            $column = $this->getColumnByField($name);
            if (!empty($column) && $column->isHidden() == false && $column->isSortable()) {
                $orders[$column->getName()] = $dir;
            }
        }

        if (!empty($mixer['order'])) {
            $column = $this->getColumnByField(key($mixer['order']));
            $action = current($mixer['order']);
            $name = $column->getName();

            if ($column) {
                if ($action == 'remove') {
                    unset($orders[$name]);
                } elseif (empty($orders[$name])) {
                    $orders[$name] = 'asc';
                } else {
                    $orders[$name] = $orders[$name] == 'asc' ? 'desc' : 'asc';
                }
            }
        }
        !empty($orders) && $url[] = $this->urlValue('order', Helper::url($orders));


        //??????????
        if (!isset($mixer['filter'])) {
            $filterData = array();
            foreach ($this->getColumns() as $name => $column) {
                if (($filter = $column->getFilter())) {
                    $value = $filter->getValue();
                    if ($value !== '' && $value !== null) {
                        $filterData[$name] = $value;
                    }
                }
            }
            !empty($filterData) && $url[] = $this->urlValue('filter', Helper::url($filterData));
        }

        //????????? ??????? ???????? ????? ? ????????????
        if ($this->getParams()) {
            $url[] = $this->urlValue('params', Helper::url($this->getParams()));
        }

        //?-?? ?? ????????
//		if ($this->getTopToolbar() && $this->getTopToolbar()->getPaginator()) {
//			$onpage = $this->getTopToolbar()->getPaginator()->getOnPage();
//			if (!empty($mixer['onpage'])) {
//				$onpage = $mixer['onpage'];
//			}
//			$onpage && $url[] = $this->urlValue('onpage', $onpage);
//		}

        //????????
        $page = 1;
        if (!empty($mixer['page']) && $this->getStorage()) {
            $page = $this->getStorage()->getPage();
        }
        $page > 1 && $url[] = $this->urlValue('page', $page);

        $separator = $this->uriDelimeter == '/' ? '/' : '?';
        if (strpos($this->getBaseUrl(), '?') !== false) {
            $separator = '&';
        }
        if (!empty($url)) {
            $url = rtrim($this->getBaseUrl(), '/') . $separator . join($this->uriDelimeter, $url);
        } else {
            $url = rtrim($this->getBaseUrl(), '/');
        }

        return $url;
    }

    /**
     * @return Boolean
     */
    public function isSelection()
    {
        return $this->selection;
    }

    /**
     * @param boolean $selection
     *
     * @return Grid
     */
    public function setSelection($selection)
    {
        $this->selection = $selection;

        return $this;
    }

    /**
     * @param boolean $allowMultipleOrdering
     *
     * @return $this;
     */
    public function setAllowMultipleOrdering($allowMultipleOrdering)
    {
        $this->allowMultipleOrdering = $allowMultipleOrdering;

        return $this;
    }

    /**
     * @return boolean
     */
    public function isAllowMultipleOrdering()
    {
        return $this->allowMultipleOrdering;
    }

    /**
     * @return \Widget\Grid\Action\Action[]
     */
    public function getActions()
    {
        return $this->actions;
    }

    /**
     * array('remove' => array(
     *        'title' => 'Remove?',
     *        'href'   => '/aaa/remove',
     * ))
     *
     * array('edit' => 'Edit', 'remove' => 'Remove item')
     *
     * @param array|\Widget\Grid\Action\Action[] $actions
     *
     * @return Toolbar
     */
    public function setActions($actions)
    {
        $this->actions = array();
        foreach ($actions as $name => &$action) {
            $this->addAction($name, $action);
        }

        return $this;
    }

    /**
     * Render grid tr
     *
     * @param array   $row
     * @param integer $index
     *
     * @return string
     */
    protected function renderTr($row, $index = 0)
    {
        $idField = $this->getStorage()->getIdField();
        $html = $index % 2 == 0 ? '<tr data-identifier="' . Helper::getValue($row, $idField) . '" class="even">' : '<tr data-identifier="' . Helper::getValue($row, $idField) . '">';
        if ($this->isSelection()) {
            $html .= '<td align="center"><input type="checkbox" name="selected[]" value="' . Helper::getValue($row, $idField) . '" /></td>';
        }

        //render coulumns
        foreach ($this->columns as &$column) {
            $html .= $column->render($row);
        }

        //render actions
        if (!empty($this->actions)) {
            $actions = '<div class="btn-group">';
            foreach ($this->actions as $action) {
                $actions .= $action->setCurrentRow($row)->render();
            }
            $actions .= '</div>';
            $html .= '<td class="last" align="center">' . $actions . '</td>';
        }

        $html .= '</tr>';

        return $html;
    }

    /**
     * Rendering summary of the table
     *
     * @return string
     */
    protected function renderSummary()
    {
        return '';
    }

    protected function urlValue($param, $value)
    {
        //????????
        $uriDelimeter = $this->uriDelimeter;
        if ($uriDelimeter == '/') {
            $valueDelimeter = '/';
        } else {
            $valueDelimeter = '=';
        }

        return $param . $valueDelimeter . $value;
    }

    /**
     * @return array
     */
    public function getParams()
    {
        return $this->params;
    }

    /**
     * @param string $key
     *
     * @return mixed
     */
    public function getParam($key)
    {
        return isset($this->params[$key]) ? $this->params[$key] : null;
    }

    /**
     * @param array $params
     *
     * @return Grid
     */
    public function setParams($params)
    {
        $this->params = $params;
        foreach ((array) $this->params as $key => $vlaue) {
            $method = 'set' . preg_replace("#_([\w])#e", "ucfirst('\\1')", ucfirst($key));
            if (method_exists($this, $method)) {
                call_user_func(array($this, $method), $vlaue);
            }
        }

        return $this;
    }

    /**
     * @param string $name
     * @param Action $action
     *
     * @return Grid
     */
    public function addAction($name, Action $action)
    {
        $action->setGrid($this);
        $this->actions[$name] = $action;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function render()
    {
        $html = parent::render();

        //save state
        $this->saveState();

        return $html;
    }

    /**
     * ?????????? ?????????
     * @return Grid
     */
    public function saveState()
    {
        if ($this->isSaveState()) {
            $state = array();
            $keys = array('hidden', 'position');
            $state['columns'] = $state['filters'] = array();
            foreach ($this->getColumns() as $name => $column) {
                foreach ($keys as $key) {
                    $method = 'get' . preg_replace("#_([\w])#e", "ucfirst('\\1')", ucfirst($key));
                    if (method_exists($column, $method)) {
                        $state['columns'][$name][$key] = call_user_func(array($column, $method));
                    }

                    $method = 'is' . preg_replace("#_([\w])#e", "ucfirst('\\1')", ucfirst($key));
                    if (method_exists($column, $method)) {
                        $state['columns'][$name][$key] = call_user_func(array($column, $method));
                    }
                }

                if (($filter = $column->getFilter()) && $filter->isState()) {
                    $state['filters'][$name] = $filter->getValue();
                }
            }

            if (!empty($order)) {
                $this->applyOrder($order);
            }

            if ($storage = $this->getStorage()) {
                $orders = array();
                $storeOrders = $storage->getOrders();
                foreach ($this->getColumns() as $column) {
                    if ($column->isHidden() == false && $column->isSortable() && isset($storeOrders[$column->getField()])) {
                        $orders[$column->getField()] = $storeOrders[$column->getField()];
                    }
                }
                $state['orders'] = $orders;

                $state['page'] = $storage->getPage();
                $state['onpage'] = $storage->getOnPage();
            }
            $this->getState()->setState($state);
        }

        return $this;
    }
}
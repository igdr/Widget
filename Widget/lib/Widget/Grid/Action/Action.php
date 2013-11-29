<?php
namespace Widget\Grid\Action;
use Widget\AbstractWidget;
use Widget\Helper;

/**
 * Grid action
 *
 * @author Drozd Igor <drozd.igor@gmail.com>
 */
class Action extends AbstractWidget
{
    /**
     * @var string
     */
    protected $title = '';

    /**
     * @var string
     */
    protected $hint = '';

    /**
     * @var string
     */
    protected $href = '';

    /**
     * @var string
     */
    protected $icon = '';

    /**
     * @var object|array
     */
    protected $row;

    /**
     * @var \Widget\Grid\Grid
     */
    protected $grid = null;

    /**
     * @param \Widget\Grid\Grid $grid
     */
    public function setGrid($grid)
    {
        $this->grid = $grid;
    }

    /**
     * @return \Widget\Grid\Grid
     */
    public function getGrid()
    {
        return $this->grid;
    }

    /**
     * @param string $href
     *
     * @return Action
     */
    public function setHref($href)
    {
        $this->href = $href;

        return $this;
    }

    /**
     * @param object|array $row
     *
     * @return string
     */
    public function getHref($row = null)
    {
        if ($row == null) {
            return $this->href;
        }

        $href = $this->href;
        if (!$href) {
            $arr = explode('?', str_replace('/view', '', $this->getGrid()->getBaseUrl()));
            $arr[0] = rtrim($arr[0], '/') . '/' . $this->getName() . '/' . Helper::getValue($row, $this->getGrid()->getStorage()->getIdField());
            $href = join('?', $arr);
        } else {
            if (preg_match_all('//{{([\d\w_]+)}}//', $href, $m)) {
                foreach ($m[1] as $key) {
                    $href = str_replace('{{' . $key . '}}', Helper::getValue($row, $key), $href);
                }
            }
        }
        $href = $href . (strpos($href, '?') === false ? '?' : '&') . 'return=' . urlencode($this->getGrid()->getUrl());

        return $href;
    }

    /**
     * @param string $icon
     *
     * @return Action
     */
    public function setIcon($icon)
    {
        $this->icon = $icon;

        return $this;
    }

    /**
     * @return string
     */
    public function getIcon()
    {
        return $this->icon ? $this->icon : $this->getName();
    }

    /**
     * @param string $title
     *
     * @return Action
     */
    public function setTitle($title)
    {
        $this->title = $title;

        return $this;
    }

    /**
     * @return string
     */
    public function getTitle()
    {
        return $this->title;
    }

    /**
     * @param string $hint
     */
    public function setHint($hint)
    {
        $this->hint = $hint;
    }

    /**
     * @return string
     */
    public function getHint()
    {
        return $this->hint;
    }

    /**
     * @param object|array $row
     * @return Action
     */
    public function setCurrentRow($row)
    {
        $this->row = $row;

        return $this;
    }

    /**
     * @return object|array
     */
    public function getCurrentRow()
    {
        return $this->row;
    }

    /**
     * {@inheritdoc}
     */
    public function initialHtml()
    {
        return '<a rel="nofollow" class="btn btn-xs btn-warning" data-role="tooltip" data-placement="top" title="' . $this->getHint() . '" href="' . $this->getHref($this->getCurrentRow()) . '"><i class="glyphicon glyphicon-' . $this->getIcon() . '"></i>'.($this->getTitle() ? ' '.$this->getTitle() : '').'</a>';
    }

}
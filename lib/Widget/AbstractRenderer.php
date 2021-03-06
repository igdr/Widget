<?php
namespace Widget;

/**
 * Class AbstractRenderer
 *
 * @author Drozd Igor <drozd.igor@gmail.com>
 */
abstract class AbstractRenderer extends ObserverAbstract implements RenderInterface
{
    /**
     * @var RendererEngine
     */
    private $rendererEngine = null;

    /**
     * @return string
     */
    abstract public function getTemplate();

    /**
     * @return \Widget\RendererEngine
     */
    public function getRendererEngine()
    {
        if ($this->rendererEngine === null) {
            $this->rendererEngine = new RendererEngine();
        }

        return $this->rendererEngine;
    }

    /**
     * @return string
     */
    protected function initialHtml()
    {
        return $this->getRendererEngine()->render($this->getTemplate(), array('element' => $this));
    }

    /**
     * Render
     *
     * @return string
     */
    public function render()
    {
        try {
            //event
            $this->fireEvent('before_render', array('widget' => $this));

            $content = $this->initialHtml();

            //event
            $this->fireEvent('after_render', array('widget' => $this, 'content' => &$content));
        } catch (\Exception $e) {
            $content = $e . '';
        }

        return $content;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->render();
    }
}

<?php
namespace view;

class View
{
    private $arr;
    private $namePage;
    private $twig;

    public function __construct($namePage, $arr, $twig)
    {
        $this->arr = $arr;
        $this->namePage = $namePage;
        $this->twig = $twig;
        $this->setPage();
    }

    public function setPage()
    {
        $template = $this->twig->load($this->namePage . '.html');
        echo $template->render($this->arr);
    }
}
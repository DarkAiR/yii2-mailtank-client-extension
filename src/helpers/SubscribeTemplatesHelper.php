<?php

namespace mailtank\helpers;

use Yii;
use yii\helpers\FileHelper;
use yii\console\Request;

class SubscribeTemplatesHelper
{
    /**
     * Create subscribe templates for mailtank by path to templates
     */
    public function createSubscribeTemplates($path)
    {
        return;
        
        // Find all template files
        $alias = Yii::getAlias($path);
        $files = FileHelper::findFiles($alias);

        // Splitting filename to parts
        $templates = [];
        foreach ($files as $f) {
            $reg = '#(?<path>'.$alias.')/(?<name>.+)\.(?<ext>html|txt)$#';
            $r = preg_match($reg, $f, $matches);
            if ($r) {
                $name = $matches['name'];
                $ext = $matches['ext'];
                $templates[$name][$ext] = 1;
            }
        }

        // Check for html and txt version
        $templates = array_filter($templates, function($value) use (&$templates) {
            if (!isset($value['txt'])) {
                echo "template '".key($templates)."' doesn't have .txt version".PHP_EOL;
                return false;
            }
            if (!isset($value['html'])) {
                echo "template '".key($templates)."' doesn't have .html version".PHP_EOL;
                return false;
            }
            return true;
        });
        
        foreach ($templates as $templateName => $dummy) {
            $this->createTemplate($templateName);
        }
    }

    /**
     * Create base template
     * @param string $templateName
     * @return string|false
     */
    private function createBaseTemplate($templateName)
    {
        $html = $this->renderTemplate($templateName.'.html');
        $html = $this->checkTemplate($templateName.'.html', $html);
        if ($html === false)
            return false;

        // Creating unique template ID from domain and template name
        $id = MailHelper::createLayoutId($templateName);
        
        try {
            $layout = new MailtankBaseLayout();
            $layout->setAttributes(
                array(
                    'id' => $id,
                )
            );
            $layout->delete();
        } catch( Exception $e ) {
            // Do nothing
        }

        $layout = new MailtankBaseLayout();
        $layout->setAttributes(
          array(
            'id' => $id,
            'name' => $id,
            'markup' => $html,
        ));
        if ($layout->save()) {
            ConsoleLog::output("Template <", false);
            ConsoleLog::setStyle("green");
            ConsoleLog::output($templateName, false);
            ConsoleLog::resetStyle();
            ConsoleLog::output("> is created, id=".$layout->id);
        } else {
            $err = $layout->getErrors();
            ConsoleLog::output("Template <", false);
            ConsoleLog::setStyle("magenta");
            ConsoleLog::output($templateName, false);
            ConsoleLog::resetStyle();
            ConsoleLog::output("> is not created.");
            ConsoleLog::addIndent();
            foreach ($err as $k=>$v) {
                ConsoleLog::error($k, false);
                ConsoleLog::error(' : ', false);
                ConsoleLog::error($v);
            }
            ConsoleLog::removeIndent();
            return false;
        }
        return $id;
    }

    private function createTemplate($templateName, $baseId = false)
    {
        $html = $this->renderTemplate($templateName.'.html');
        $textPlain = $this->renderTemplate($templateName.'.txt');

        // Закомментирован, т.к. https://github.com/mediasite/gpor/issues/2775
        // Пока не изменим workflow нам emogrifier будет только вредить

        // Вставляем стили inline
        /*if (preg_match('/<style.*?>(.*?)<\/style>/usix', $html, $matches)) {
            require_once(LIB_PATH. DS. 'emogrifier'. DS .'emogrifier.php');

            $html = preg_replace('/<style.*?>(.*?)<\/style>/usix','', $html);

            $encoding = mb_detect_encoding($html);
            $html = mb_convert_encoding($html, 'HTML-ENTITIES', $encoding);
            $xmldoc = new DOMDocument;
            $xmldoc->encoding = $encoding;
            $xmldoc->strictErrorChecking = false;
            $xmldoc->formatOutput = true;
            @$xmldoc->loadHTML($html);
            $xmldoc->normalizeDocument();
            $html = $xmldoc->saveHTML();

            $parse = new Emogrifier($html, $matches[1]);
            $html = $parse->emogrify();

            // Выправляем получившийся код, т.к. теперь он содержит всякие %20
            $html = urldecode($html);
        }*/

        // Проверяем ошибки в шаблонах
        $html = $this->checkTemplate($templateName.'.html', $html);
        if ($html === false)
            return;
        $textPlain = $this->checkTemplate($templateName.'.txt', $textPlain);
        if ($textPlain === false)
            return;

        // Создаем уникальный ID шаблона из домена и имени шаблона
        $id = MailHelper::createLayoutId( $templateName );

        try {
            $layout = new MailtankLayout();
            $layout->setAttributes(
                array(
                    'id' => $id,
                )
            );
            $layout->delete();
        } catch( Exception $e ) {
            // Do nothing
        }

        $layout = new MailtankLayout();
        $attr = array(
            'id' => $id,
            'name' => $id,
            'subject_markup' => '{{subject}}',
            'markup' => $html,
            'plaintext_markup' => $textPlain,
        );
        if ($baseId)
            $attr['base'] = $baseId;

        $layout->setAttributes($attr);
        if ($layout->save()) {
            ConsoleLog::output("Template <", false);
            ConsoleLog::setStyle("green");
            ConsoleLog::output($templateName, false);
            ConsoleLog::resetStyle();
            ConsoleLog::output("> is created, id=".$layout->id);
        } else {
            $err = $layout->getErrors();
            ConsoleLog::output("Template <", false);
            ConsoleLog::setStyle("magenta");
            ConsoleLog::output($templateName, false);
            ConsoleLog::resetStyle();
            ConsoleLog::output("> is not created.");
            ConsoleLog::addIndent();
            foreach ($err as $k=>$v) {
                ConsoleLog::error($k, false);
                ConsoleLog::error(' : ', false);
                ConsoleLog::error($v);
            }
            ConsoleLog::removeIndent();
        }
    }

    public function getOptionHelp()
    {
        return array(
            "Create subscribe templates.",
            "",
        );
    }

    /**
     * Check template
     */
    private function checkTemplate($templateName, $text)
    {
        // NOTE: Замена должна происходить до preg_match_all, чтобы в поиск ($matches) попали уже новые фильтры
        //
        // 1. Обрабатываем разрешенные фильтры
        $text = preg_replace('/\|raw(\s|\||\)|\})/', '|safe$1', $text);

        // 2. Если ваще фильтров нет, то выходим сразу
        if (!preg_match_all('/\|\w*/', $text, $matches, PREG_PATTERN_ORDER | PREG_OFFSET_CAPTURE))
            return $text;

        $findError = false;
        foreach ($matches[0] as $match) {
            // Пропускаем разрешенные фильтры
            if (in_array($match[0], array('|safe', '|length', '|capitalize')))
                continue;

            $findError = true;
            $start = strrpos($text, "\n", $match[1]-strlen($text));
            if ($start === false) {
                $start = ($match[1] > 32)
                    ? $match[1] - 32
                    : 0;
            }
            $end = strpos($text, "\n", $match[1]);
            if ($end === false) {
                $end = (strlen($text) - $match[1] >= 32)
                    ? $match[1] + 32
                    : strlen($text)-1;
            }
            echo PHP_EOL.'<'.$templateName.'> find filter: '.$match[0].PHP_EOL.''.trim(substr($text, $start, $end-$start)).PHP_EOL;
        }
        if (!$findError)
            return $text;

        echo PHP_EOL;

        return false;
    }

    /**
     * Render mail template
     */
    private function renderTemplate($filename, $absolutePath = false)
    {
        $viewfile = './extensions/mailer/views/mailtankMessages/'.$filename;
        $file = $absolutePath ? $viewfile : Yii::app()->getBasePath().'/'.$viewfile;
        $content = file_get_contents($file);

        // Находим наследование
        $res = preg_match_all('/\{%\s*extends\s*(?s)(?>\'|\")(.*?)(?>\'|\")(?-s)\s*%\}/', $content, $matchesExtends);
        if ($res === FALSE)
            throw new Exception('Regular expression error');

        // Если шаблон унаследован, вставляем все его блоки в родительский шаблон
        if ($res !== 0) {
            // Вырезаем все блоки
            $res = preg_match_all('/(?s)\{%\s*block\s+(\S+)\s*%\}(.*?)\{%\s*endblock\s*%\}(?-s)/', $content, $matchesBlocks);
            if ($res === FALSE)
                throw new Exception('Regular expression error');

            $blocks = array_combine($matchesBlocks[1], $matchesBlocks[2]);

            // Подставляем блоки в базовый шаблон
            $baseViewfile = './extensions/mailer/views/mailtankMessages/'.$matchesExtends[1][0];
            $baseFile = $absolutePath ? $baseViewfile : Yii::app()->getBasePath().'/'.$baseViewfile;
            $baseContent = file_get_contents($baseFile);

            foreach ($blocks as $key=>$block) {
                $baseContent = preg_replace('/(?s)\{%\s*block\s+'.$key.'\s*%\}.*?\{%\s*endblock\s*%\}(?-s)/', $block, $baseContent);
            }

            $content = $baseContent;
        }

        return $content;
    }


    /**
     * Creating ID to template for mailtank
     */
    private static function createLayoutId($templateName)
    {
        $headers = Yii::$app->request->getHeaders();
        if ($headers->hasProperty('host')) {
            var_dump($headers->host);
        }

        $id = preg_replace("/(\/|\.)/u", '_', str_replace("\\", "/", Yii::app()->params['siteDomain']))
            . '_'
            . preg_replace("/(\/|\.)/u", '_', str_replace("\\", "/", $templateName));

        return $id;
    }
}
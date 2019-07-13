<?php

namespace App\Command;

use ParseError;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DomCrawler\Crawler;

class GenerateSchemaCommand extends ContainerAwareCommand
{
    /** @var string */
    private const BOT_DOCUMENTATION_URL = 'https://core.telegram.org/bots/api';

    /** @var array */
    private $schema;

    protected function configure(): void
    {
        $this->setName('generate:schema');
    }

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     * @return int|void|null
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $buildDirSource = $this->getContainer()->getParameter('kernel.root_dir') . '/../build/source/';
        $htmlPath = $buildDirSource . 'schema.html';
        $jsonPath = $buildDirSource . 'schema.json';

        if (!file_exists($htmlPath)) {
            $contents = file_get_contents(self::BOT_DOCUMENTATION_URL);
            file_put_contents($htmlPath, $contents);
        }

        $html = file_get_contents($htmlPath);

        $this->schema = [
            'objects' => [],
            'methods' => [],
        ];

        $crawler = new Crawler($html);
        $node = $crawler->filter('table.table');
        $methodNodes = [];
        $node->each(function (Crawler $tableNode) use (&$methodNodes) {

            $methodOrObjectName = '';
            $descriptions = [];
            $tableNode->previousAll()->each(function (Crawler $node) use (&$methodOrObjectName, &$methodNodes, $tableNode, &$descriptions) {
                if ($methodOrObjectName === '' && 'h4' === $node->nodeName()) {
                    $methodOrObjectName = $node->text();
                    if (ctype_upper($methodOrObjectName[0])) {

                        $fields = [];
                        $tableNode->filter('tbody tr')->each(function (Crawler $rowNode, $rowNumber) use (&$fields) {
                            if ($rowNumber === 0) {
                                return;
                            }

                            if (0 === strpos($rowNode->filter('td:nth-child(3)')->text(), 'Optional')) {
                                $required = false;
                            } else {
                                $required = true;
                            }

                            $fields[] = [
                                'name'        => $rowNode->filter('td:nth-child(1)')->text(),
                                'roughType'   => $rowNode->filter('td:nth-child(2)')->text(),
                                'description' => $rowNode->filter('td:nth-child(3)')->text(),
                                'required'    => $required,
                            ];
                        });

                        $this->schema['types'][$methodOrObjectName] = [
                            'name'         => $methodOrObjectName,
                            'link'         => $node->filter('a')->attr('href'),
                            'fields'       => $fields,
                            'descriptions' => $descriptions,
                        ];


                    } else {
                        $methodNodes[$methodOrObjectName] = [
                            'tableNode'    => $tableNode,
                            'descriptions' => array_merge([], $descriptions),
                        ];
                    }
                } else {
                    $descriptions[] = $node->html();
                }
            });
        });

        foreach ($this->schema['types'] as &$type) {
            foreach ($type['fields'] as &$field) {
                $field['type'] = $this->parseType($field['roughType']);
                if (false !== strpos('Required', $field['description'])) {
                    $field['required'] = true;
                }
                $field['is_object'] = $this->isObject($field['type']);
                $field['is_multiple_types'] = count(explode('|', $field['type'])) > 1;
                $field['is_collection'] = strpos($field['type'], '[]') !== false;
                unset($field['roughType']);
            }
            unset($field);

            $type['parent'] = $this->getParent($type['name']);
        }
        unset($type);

        foreach ($methodNodes as $methodName => $method) {
            $tableNode = $method['tableNode'];
            $descriptions = $method['descriptions'];
            $parameters = [];
            $tableNode->filter('tbody tr')->each(function (Crawler $rowNode, $rowNumber) use (&$parameters) {
                if ($rowNumber === 0) {
                    return;
                }

                $type = $this->parseType($rowNode->filter('td:nth-child(2)')->text());
                $description = $rowNode->filter('td:nth-child(4)')->text();

                $required = (false !== strpos($description, 'Required'));

                if ($required === false) {
                    $required = $this->parseRequired($rowNode->filter('td:nth-child(3)')->text());
                }

                $parameters[] = [
                    'name'              => $rowNode->filter('td:nth-child(1)')->text(),
                    'type'              => $type,
                    'is_multiple_types' => count(explode('|', $type)) > 1,
                    'is_collection'     => strpos($type, '[]') !== false,
                    'is_object'         => $this->isObject($type),
                    'required'          => $required,
                    'description'       => $description,
                ];
            });

            $description = implode("\n", $descriptions);

            $this->schema['methods'][] = [
                'name'              => $methodName,
                'parameters'        => $parameters,
                'return'            => $type = $this->getReturnType($description),
                'is_multiple_types' => count(explode('|', $type)) > 1,
                'is_collection'     => strpos($type, '[]') !== false,
                'description'       => $description,
                'link'              => $node->filter('a')->attr('href'),
            ];

        }

        file_put_contents($jsonPath, json_encode($this->schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo realpath($jsonPath);
    }

    private function parseRequired($text)
    {
        if ($text === 'Yes') {
            $text = true;
        } elseif ($text === 'True') {
            $text = true;
        } elseif ($text === 'Optional') {
            $text = false;
        } elseif ($text === 'No') {
            $text = false;
        } else {
            throw new ParseError('Unexpected required: ' . $text);
        }

        return $text;
    }

    /**
     * @param $text
     * @return string
     */
    private function parseType($text): string
    {
        if (false !== strpos($text, ' or ')) {
            $pieces = explode(' or ', $text);
            $types = [];
            foreach ($pieces as $piece) {
                $types[] = $this->parseType($piece);
            }

            return implode('|', $types);
        }

        if (false !== strpos($text, 'Array of ')) {
            [$_, $text] = explode(' of ', $text);
            $type = $this->parseType($text);
            return $type . '[]';
        }

        if ($text === 'Float' || $text === 'Float number') {
            return 'float';
        }

        if ($text === 'Integer') {
            return 'int';
        }

        if ($text === 'True') {
            return 'bool';
        }

        if ($text === 'CallbackGame') {
            return 'array';
        }

        if ($text === 'Array') {
            return 'array';
        }

        if ($text === 'Integer or String') {
            return 'string';
        }

        if ($text === 'Boolean') {
            return 'bool';
        }

        if ($text === 'String') {
            return 'string';
        }

        if ($text === 'Array of String') {
            return 'string[]';
        }

        if ($text === 'InputFile') {
            return $this->getClassName('InputFileInterface');
        }

        if ($text === 'InlineQueryResult') {
            return $this->getClassName('AbstractInlineQueryResult');
        }

        if ($text === 'InputMessageContent') {
            return $this->getClassName('AbstractInputMessageContent');
        }

        if ($this->isObject($text)) {
            return $this->getClassName($text);
        }

        throw new ParseError('Unexpected type: ' . $text);
    }

    private function getClassName(string $className): string
    {
        return '\\' . GenerateClientCommand::BASE_NAMESPACE_TYPES . '\\' . $className;
    }

    /**
     * @param $text
     * @return bool
     */
    private function isObject($text): bool
    {
        foreach ($this->schema['types'] as $type) {
            if ($type['name'] === $text) {
                return true;
            }
        }

        try {
            $this->getParent($text);
            return true;
        } catch (ParseError $e) {
            return false;
        }
    }

    private function getParent($type): string
    {
        if (0 === strpos($type, 'InlineQueryResult')) {
            return $this->getClassName('AbstractInlineQueryResult');
        }

        if (0 === strpos($type, 'Input') && false !== strpos($type, 'MessageContent')) {
            return $this->getClassName('AbstractInputMessageContent');
        }

        if (ctype_upper($type[0])) {
            return $this->getClassName('AbstractType');
        }

        throw new ParseError('Cannot determine parent of type: ' . $type);
    }

    private function getReturnType($description)
    {
        $href = '\<a href\=\".*?\#(?<object>.*?)\"\>.*?\<\/a\>';
        $em = '\<em\>(?<simple>.*?)\<\/em\>';
        $regexps = [
            "/An (?<array>Array) of {$href} objects is returned/",
            "/Returns {$em} on success/",
            "/Returns the new invite link as {$em} on success/",
            "/Returns a {$href}(?: object)?(?: on success)?\./",
            "/Returns the uploaded {$href} on success/",
            "/On success, the sent {$href} is returned/",
            "/On success, an (?<array>array) of the sent {$href} is returned/",
            "/On success, if the edited message was sent by the bot, the edited {$href} is returned, otherwise {$em} is returned/",
            "/On success, if the message was sent by the bot, the sent {$href} is returned, otherwise {$em} is returned/",
            "/On success, a {$href} object is returned/",
            "/On success, returns an (?<array>Array) of {$href} objects/",
            "/On success, {$em} is returned/",
            "/On success, if edited message is sent by the bot, the edited {$href} is returned, otherwise {$em} is returned/",
            "/On success, the stopped {$href} with the final results is returned/",
            '/On success, (?<simple>True) is returned/',
            "/On success, if the message was sent by the bot, returns the edited {$href}, otherwise returns {$em}. Returns an error/",
            "/On success, returns an \<em\>(?<array>Array)\<\/em\> of {$href} objects/",
        ];
        $matchedTypes = [];
        foreach ($regexps as $regexp) {
            if (preg_match($regexp, $description, $match)) {
                $type = '';
                $types = [];
                if (isset($match['object'])) {
                    if (isset($match['array'])) {
                        $type .= 'Array of ';
                    }

                    $types[] = $type . ucfirst($match['object']);
                }
                if (isset($match['simple'])) {
                    $types[] = $match['simple'];
                }

                $matchedTypes[] = $types;
            }
        }

        if (count($matchedTypes) > 1) {
            throw new \RuntimeException('Matched more than 1 return types');
        }

        if (count($matchedTypes) === 0) {
            throw new \RuntimeException('return type does not found');
        }

        $returnObjects = [];
        foreach ($matchedTypes[0] as $type) {
            $returnObjects[] = $this->parseType($type);
        }

        return implode('|', $returnObjects);
    }

}
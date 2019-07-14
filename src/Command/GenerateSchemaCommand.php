<?php

namespace App\Command;

use ParseError;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DomCrawler\Crawler;

class GenerateSchemaCommand extends ContainerAwareCommand
{
    /** @var string */
    private const BOT_DOCUMENTATION_URL = 'https://core.telegram.org/bots/api';

    private const TRANSFORMS = [
        'PassportElementError' => [
            'PassportElementErrorDataField',
            'PassportElementErrorFrontSide',
            'PassportElementErrorReverseSide',
            'PassportElementErrorSelfie',
            'PassportElementErrorFile',
            'PassportElementErrorFiles',
            'PassportElementErrorTranslationFile',
            'PassportElementErrorTranslationFiles',
            'PassportElementErrorUnspecified',
        ],
        'InputMedia'           => [
            'InputMediaAnimation',
            'InputMediaDocument',
            'InputMediaAudio',
            'InputMediaPhoto',
            'InputMediaVideo',
        ],
    ];

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
            'types'   => [],
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
                $field['required'] = stripos('Required', $field['description']) !== false;
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
                    'name'        => $rowNode->filter('td:nth-child(1)')->text(),
                    'type'        => $type,
                    'required'    => $required,
                    'description' => $description,
                ];
            });

            $description = implode("\n", $descriptions);

            $this->schema['methods'][] = [
                'name'        => $methodName,
                'parameters'  => $parameters,
                'return'      => $this->getReturnType($description),
                'description' => $description,
                'link'        => $node->filter('a')->attr('href'),
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
     * @return string|string[]
     */
    private function parseType($text)
    {
        if (strpos($text, 'Array of ') !== false) {
            [, $text] = explode(' of ', $text);
            $types = $this->parseType($text);
            foreach ($types as &$type) {
                $type[1] = true;
            }

            return $types;
        }

        if (false !== strpos($text, ' or ') || false !== strpos($text, ' and ')) {
            $divider = strpos($text, ' or ') !== false ? ' or ' : ' and ';
            $pieces = explode($divider, $text);
            $types = [];
            foreach ($pieces as $piece) {
                $types[] = $this->parseType($piece);
            }

            return array_merge(...$types);
        }


        if ($text === 'Float' || $text === 'Float number') {
            return [['float', false]];
        }

        if ($text === 'Integer' || $text === 'Int') {
            return [['int', false]];
        }

        if ($text === 'True' || $text === 'Boolean') {
            return [['bool', false]];
        }

        if ($text === 'CallbackGame' || $text === 'Array') {
            return [['array', false]];
        }

        if ($text === 'String' || $text === 'Integer or String') {
            return [['string', false]];
        }

        if ($text === 'Array of String') {
            return [['string', true]];
        }

        if ($text === 'InputFile') {
            return [[$this->getClassName('InputFileInterface'), false]];
        }

        if ($text === 'InlineQueryResult') {
            return [[$this->getClassName('AbstractInlineQueryResult'), false]];
        }

        if ($text === 'InputMessageContent') {
            return [[$this->getClassName('AbstractInputMessageContent'), false]];
        }

        if (isset(self::TRANSFORMS[$text])) {
            return array_map(function ($type) {
                return [$type, false];
            }, array_map([$this, 'getClassName'], self::TRANSFORMS[$text]));
        }

        if ($this->isObject($text)) {
            return [[$this->getClassName($text), false]];
        }

        throw new ParseError("Unexpected type: {$text}");
    }

    private function getClassName(string $className): string
    {
        return '\\' . GenerateClientCommand::BASE_NAMESPACE_TYPES . '\\' . $className;
    }

    /**
     * @param $text
     * @return bool
     */
    private function isObject(string $text): bool
    {
        if (isset($this->schema['types'][$text])) {
            return true;
        }

        throw new ParseError("Undefined type: {$text}");
    }

    private function getParent(string $type): string
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
        $href = '\<a href\=\".*?\#(?<objectAnchor>.*?)\"\>(?<objectName>.*?)\<\/a\>';
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
                foreach (['objectName', 'simple'] as $matchType) {
                    if (isset($match[$matchType])) {
                        // f**k https://core.telegram.org/bots/api#sendmediagroup
                        if ($matchType === 'objectName' && strtolower($match['objectName']) !== strtolower($match['objectAnchor'])) {
                            $matchType = 'objectAnchor';
                        }
                        foreach ($this->parseType(ucfirst($match[$matchType])) as $type) {
                            $type[1] = isset($match['array']);
                            $matchedTypes[] = $type;
                        }
                    }
                }
            }
        }

        if (count($matchedTypes) === 0) {
            throw new RuntimeException('return type does not found');
        }

        return $matchedTypes;
    }

}
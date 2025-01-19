<?php

namespace FQL\Stream;

use FQL\Enum;
use FQL\Exceptions;

/**
 * @phpstan-import-type StreamProviderArrayIterator from ArrayStreamProvider
 * @phpstan-import-type StreamProviderArrayIteratorValue from ArrayStreamProvider
 */
abstract class XmlProvider extends StreamProvider
{
    private ?string $inputEncoding = null;

    protected function __construct(private readonly string $xmlFilePath)
    {
    }

    public function setInputEncoding(?string $encoding): self
    {
        $this->inputEncoding = $encoding;
        return $this;
    }

    /**
     * @param string|null $query
     * @return ?StreamProviderArrayIterator
     */
    public function getStream(?string $query): ?\ArrayIterator
    {
        $generator = $this->getStreamGenerator($query);
        return $generator ? new \ArrayIterator(iterator_to_array($generator)) : null;
    }

    /**
     * @throws Exceptions\UnexpectedValueException
     * @throws Exceptions\InvalidArgumentException
     */
    public function getStreamGenerator(?string $query): ?\Generator
    {
        if ($query === null) {
            throw $this->createEmptyQueryException();
        }

        $xmlReader = \XMLReader::open($this->xmlFilePath, $this->inputEncoding);
        if (!$xmlReader) {
            throw new Exceptions\InvalidArgumentException('Unable to open XML file.');
        }

        $depth = substr_count($query, '.');
        if ($depth === 0) {
            $depth = 1;
        }

        while ($xmlReader->read()) {
            if (
                $xmlReader->nodeType == \XMLReader::ELEMENT
                && ($query !== '' && in_array($xmlReader->localName, explode('.', $query)) || $query === '*')
                && $xmlReader->depth === $depth
            ) {
                try {
                    $item = new \SimpleXMLElement($xmlReader->readOuterXml(), LIBXML_NOCDATA);
                    yield $this->itemToArray($item);
                } catch (\Exception $e) {
                    trigger_error($e->getMessage(), E_USER_WARNING);
                    break;
                }
            }
        }
        $xmlReader->close();
    }

    /**
     * @param \SimpleXMLElement $element
     * @return string|StreamProviderArrayIteratorValue
     */
    private function itemToArray(\SimpleXMLElement $element): string|array
    {
        $result = [];

        // Convert attributes to an array under the key '@attributes'
        foreach ($element->attributes() as $attributeName => $attributeValue) {
            $result['@attributes'][$attributeName] = Enum\Type::matchByString($attributeValue);
        }

        // Conversion of attributes with namespaces
        foreach ($element->getNamespaces(true) as $prefix => $namespace) {
            foreach ($element->attributes($namespace) as $attributeName => $attributeValue) {
                $key = $prefix ? "{$prefix}:{$attributeName}" : $attributeName;
                $result['@attributes'][$key] = Enum\Type::matchByString($attributeValue);
            }
        }

        // Conversion of child elements to an array
        foreach ($element->children() as $childName => $childElement) {
            $childArray = $this->itemToArray($childElement);
            if (isset($result[$childName])) {
                // If multiple elements with the same name exist, create an array
                if (!is_array($result[$childName]) || !isset($result[$childName][0])) {
                    $result[$childName] = [$result[$childName]];
                }
                $result[$childName][] = $childArray;
            } else {
                $result[$childName] = is_string($childArray) ? Enum\Type::matchByString($childArray) : $childArray;
            }
        }

        // Conversion of child elements with namespaces
        foreach ($element->getNamespaces(true) as $prefix => $namespace) {
            foreach ($element->children($namespace) as $childName => $childElement) {
                $key = $prefix ? "{$prefix}:{$childName}" : $childName;
                $childArray = $this->itemToArray($childElement);
                if (isset($result[$key])) {
                    if (!is_array($result[$key]) || !isset($result[$key][0])) {
                        $result[$key] = [$result[$key]];
                    }
                    $result[$key][] = $childArray;
                } else {
                    $result[$key] = $childArray;
                }
            }
        }

        // If the element has no children and attributes, return a simple value
        $value = trim((string) $element);
        if ($value !== '' && empty($result)) {
            return Enum\Type::matchByString($value);
        }

        // If the element has children or attributes but also a text value, add it as 'value'
        if ($value !== '') {
            $result['value'] = Enum\Type::matchByString($value);
        }

        return $result;
    }

    public function getXmlFilePath(): string
    {
        return $this->xmlFilePath;
    }

    public function getInputEncoding(): ?string
    {
        return $this->inputEncoding;
    }

    public function provideSource(): string
    {
        $source = '';
        if ($this->xmlFilePath !== '') {
            $source = sprintf('[xml](%s)', basename($this->xmlFilePath));
        }
        return $source;
    }

    /**
     * @return Exceptions\UnexpectedValueException
     */
    private function createEmptyQueryException(): Exceptions\UnexpectedValueException
    {
        $xmlReader = \XMLReader::open($this->xmlFilePath, $this->inputEncoding);
        if (!$xmlReader) {
            throw new Exceptions\InvalidArgumentException('Unable to open XML file.');
        }

        $elements = [];
        while ($xmlReader->read()) {
            if ($xmlReader->nodeType == \XMLReader::ELEMENT) {
                $elements[] = $xmlReader->localName;
                if (count($elements) == 2) {
                    break;
                }
            }
        }

        return new Exceptions\UnexpectedValueException(
            sprintf('Empty query. Try to use "%s" query', implode('.', $elements))
        );
    }
}

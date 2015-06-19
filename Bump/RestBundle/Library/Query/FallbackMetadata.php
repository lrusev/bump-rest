<?php
namespace Bump\RestBundle\Library\Query;

use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\ORM\Mapping\ClassMetadata;

class FallbackMetadata
{
    private $metadata;
    private $valueField;

    public function __construct(ClassMetadata $metadata, $valueField = 'value')
    {
        if (count($metadata->identifier) !== 2) {
            throw new \InvalidArgumentException("Invalid fallback metadata, expected for double composite keys");
        }

        if (!isset($metadata->fieldMappings[$valueField])) {
            throw new \InvalidArgumentException("Invalid fallback metadata, doesn't contain valueField: {$valueField}");
        }

        $this->metadata = $metadata;
        $this->valueField = $valueField;
    }

    public function isSupport(ClassMetadata $target)
    {
        return null===$this->getAssociation($target)?false:true;
    }

    public function getAssociation(ClassMetadata $target)
    {
        foreach ($this->metadata->getAssociationMappings() as $meta) {
            if ($meta['targetEntity'] === $target->name) {
                $meta['fallbackJoin'] = true;
                return $meta;
            }
        }

        return;
    }

    public function getFallbackField(ClassMetadata $target)
    {
        $meta = $this->getAssociation($target);
        if (!in_array($meta['fieldName'], $this->metadata->identifier)) {
            throw new \InvalidArgumentException("Invalid metadata");
        }

        $diff = array_diff($this->metadata->identifier, array($meta['fieldName']));
        $fallback = reset($diff);
        return $this->metadata->fieldMappings[$fallback];
    }

    public function getValueField()
    {
        return $this->metadata->fieldMappings[$this->valueField];
    }
}

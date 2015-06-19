<?php

namespace Bump\RestBundle\Library;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\RequestContext as Context;
use Hateoas\Configuration\Metadata\ClassMetadataInterface;
use Hateoas\Configuration\Relation;
use Imagine\Image as Image;
use Imagine\Gd\Imagine;
use RuntimeException;

trait SingleImageUploadable
{
    use Url;

    private $temp;

    /**
     * @ORM\PostPersist
     * @ORM\PostUpdate
     */
    public function generateThumbnails()
    {
        $fs = new Filesystem();
        if (isset($this->temp)) {
            $fs->remove($this->temp);
            $targetDir = dirname($this->temp).DIRECTORY_SEPARATOR.$this->getId();
            $this->temp = null;
            if ($fs->exists($targetDir)) {
                $fs->remove($targetDir);
            }
        }

        $asset = $this->getAsset();
        if (!($asset instanceof UploadedFile)) {
            return;
        }

        $sizes = $this->getThumbnailSizes();
        if (empty($sizes)) {
            return;
        }

        $original = $this->getImagePath();
        $namePrefix = pathinfo($original, PATHINFO_FILENAME);
        $extension = $this->getThumbnailExtension();

        $targetDir = dirname($original).DIRECTORY_SEPARATOR.$this->getId();
        if ($fs->exists($targetDir)) {
            $fs->remove($targetDir);
        }

        $fs->mkdir($targetDir, 0774);

        $mode = $this->getThumnailMode();

        $imagine = new Imagine();
        $image = $imagine->open($original);

        foreach ($sizes as $name => $size) {
            if (is_array($size)) {
                list($width, $height) = $size;
            } elseif (false !== strpos($size, 'x')) {
                $name = $size;
                list($width, $height) = explode('x', $size);
            } else {
                continue;
            }

            $size = new Image\Box($width, $height);
            $image->thumbnail($size, $mode)
                  ->save($targetDir.DIRECTORY_SEPARATOR.$namePrefix."-{$width}x{$height}.".$extension);
        }
    }

    /**
     * @ORM\PreRemove()
     */
    public function removeUpload()
    {
        if (($file = $this->getImagePath())) {
            $thumbnailPath = dirname($file).DIRECTORY_SEPARATOR.$this->getId();
            $fs = new Filesystem();
            $fs->remove($file);
            if ($fs->exists($thumbnailPath)) {
                $fs->remove($thumbnailPath);
            }
        }
    }

    public function getUploadRootDir($basePath)
    {
        return $basePath.DIRECTORY_SEPARATOR.$this->getUploadDir();
    }

    public function getRelativePath()
    {
        return $this->getUploadDir().DIRECTORY_SEPARATOR.basename($this->getImagePath());
    }

    public function getThumbnailSizes()
    {
        return [];
    }

    public function getThumnailMode()
    {
        return Image\ImageInterface::THUMBNAIL_INSET;
    }

    public function getThumbnailPathBySize($iconWidth, $iconHeight, $absolute = true)
    {
        $iconWidth = (int) $iconWidth;
        $iconHeight = (int) $iconHeight;

        $original = $this->getImagePath();
        $sizes = $this->getThumbnailSizes();
        if (empty($original) || empty($sizes)) {
            throw new RuntimeException("Required icon is not available");
        }

        $namePrefix = pathinfo($original, PATHINFO_FILENAME);
        foreach ($sizes as $name => $size) {
            if (is_array($size)) {
                list($width, $height) = $size;
            } elseif (false !== strpos($size, 'x')) {
                if (!is_string($name)) {
                    $name = $size;
                }

                list($width, $height) = explode('x', $size);
            } else {
                continue;
            }

            if ($width == $iconWidth && $height == $iconHeight) {
                $thumbnail = $namePrefix."-{$width}x{$height}";

                return $this->getThumbnailPath($thumbnail, $absolute);
            }
        }

        throw new RuntimeException("There on icon available by size {$width}x{$height}");
    }

    public function getThumbnailPath($name, $absolute = true)
    {
        $original = $this->getImagePath();
        $name .= '.'.$this->getThumbnailExtension();

        if ($absolute) {
            return dirname($original).DIRECTORY_SEPARATOR.$this->getId().DIRECTORY_SEPARATOR.$name;
        } else {
            return $this->getUploadDir().DIRECTORY_SEPARATOR.$this->getId().DIRECTORY_SEPARATOR.$name;
        }
    }

    public function getThumbnailUrl(Context $context, $name)
    {
        return $this->getUrl($context, $this->getThumbnailPath($name, false));
    }

    public function getImageRelations($entity, ClassMetadataInterface $classMetadata)
    {
        $relations = array();
        $original = $entity->getImagePath();
        $sizes = $entity->getThumbnailSizes();
        if (empty($original) || empty($sizes)) {
            return;
        }

        $namePrefix = pathinfo($original, PATHINFO_FILENAME);
        $rel = $entity->getOriginalRel();
        $relations[] = new Relation(
            $rel,
            "expr(object.getUrl(service('router').getContext(), object.getRelativePath()))"
        );

        foreach ($sizes as $name => $size) {
            if (is_array($size)) {
                list($width, $height) = $size;
            } elseif (false !== strpos($size, 'x')) {
                if (!is_string($name)) {
                    $name = $size;
                }

                list($width, $height) = explode('x', $size);
            } else {
                continue;
            }

            $thumbnail = $namePrefix."-{$width}x{$height}";

            $relations[] = new Relation(
                $rel.'-'.$name,
                "expr(object.getThumbnailUrl(service('router').getContext(), '{$thumbnail}'))",
                null,
                array('width' => $width, 'height' => $height)
            );
        }

        return $relations;
    }

    public function getOriginalRel()
    {
        return 'oringinal';
    }

    public function getThumbnailExtension()
    {
        return 'png';
    }

    abstract public function getImagePath();
    abstract public function getUploadDir();
    abstract public function getAsset();
}

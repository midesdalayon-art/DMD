import { ChevronLeft, ChevronRight, X } from 'lucide-react'
import { useCallback, useEffect, useRef, useState } from 'react'

import ResortImagePlaceholder from './ResortImagePlaceholder'
import { resolveAssetUrl } from '../lib/api'

function ImageGallery({ className = '', images = [], imagePath, title }) {
  const [activeImageIndex, setActiveImageIndex] = useState(null)
  const [currentMobileImage, setCurrentMobileImage] = useState(0)
  const galleryRef = useRef(null)
  const galleryImages = (images.length > 0
    ? images
    : imagePath
      ? [{ id: 'legacy-primary', url: imagePath, is_primary: true }]
      : [])
    .map((image) => ({ ...image, url: image.url ?? image.image_path }))
    .filter((image) => image.url)

  const primaryImage = galleryImages.find((image) => image.is_primary) ?? galleryImages[0] ?? null
  const orderedGalleryImages = primaryImage
    ? [
        primaryImage,
        ...galleryImages.filter((image) => image.id !== primaryImage.id || image.url !== primaryImage.url),
      ].slice(0, 5)
    : []
  const secondaryImages = orderedGalleryImages.slice(1)
  const hasMoreImages = galleryImages.length > orderedGalleryImages.length
  const galleryClassName = [
    'image-gallery',
    className,
    secondaryImages.length > 0 ? 'has-secondary' : 'single-image',
    `gallery-count-${Math.min(orderedGalleryImages.length || 1, 5)}`,
  ].filter(Boolean).join(' ')
  const isLightboxOpen = activeImageIndex !== null
  const activeImage = isLightboxOpen ? orderedGalleryImages[activeImageIndex] : null

  function openLightbox(index) {
    setActiveImageIndex(index)
  }

  function updateMobileCounter(event) {
    const element = event.currentTarget
    const imageWidth = element.clientWidth

    if (imageWidth > 0) {
      setCurrentMobileImage(Math.round(element.scrollLeft / imageWidth))
    }
  }

  const closeLightbox = useCallback(function closeLightbox() {
    setActiveImageIndex(null)
  }, [])

  const showPreviousImage = useCallback(function showPreviousImage() {
    setActiveImageIndex((currentIndex) => {
      if (currentIndex === null || orderedGalleryImages.length <= 1) return currentIndex
      return currentIndex === 0 ? orderedGalleryImages.length - 1 : currentIndex - 1
    })
  }, [orderedGalleryImages.length])

  const showNextImage = useCallback(function showNextImage() {
    setActiveImageIndex((currentIndex) => {
      if (currentIndex === null || orderedGalleryImages.length <= 1) return currentIndex
      return currentIndex === orderedGalleryImages.length - 1 ? 0 : currentIndex + 1
    })
  }, [orderedGalleryImages.length])

  useEffect(() => {
    if (!isLightboxOpen) return undefined

    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'

    function handleKeyDown(event) {
      if (event.key === 'Escape') {
        closeLightbox()
      }

      if (event.key === 'ArrowLeft') {
        showPreviousImage()
      }

      if (event.key === 'ArrowRight') {
        showNextImage()
      }
    }

    window.addEventListener('keydown', handleKeyDown)

    return () => {
      document.body.style.overflow = previousOverflow
      window.removeEventListener('keydown', handleKeyDown)
    }
  }, [closeLightbox, isLightboxOpen, showNextImage, showPreviousImage])

  return (
    <>
      <div
        className={galleryClassName}
        ref={galleryRef}
        aria-label={`${title} image gallery`}
        onScroll={updateMobileCounter}
      >
        <div className="gallery-primary">
          {primaryImage?.url ? (
            <button
              className="gallery-image-button"
              type="button"
              onClick={() => openLightbox(0)}
              aria-label={`Open ${title} primary image`}
            >
              <img
                src={resolveAssetUrl(primaryImage.large_url ?? primaryImage.medium_url ?? primaryImage.url)}
                srcSet={buildSrcSet(primaryImage)}
                sizes="(max-width: 767px) 100vw, (max-width: 1280px) 70vw, 960px"
                alt={title}
                fetchPriority="high"
                loading="eager"
                decoding="async"
              />
            </button>
          ) : (
            <ResortImagePlaceholder label="Primary photo pending" variant="gallery" />
          )}
        </div>
        {secondaryImages.length > 0 ? (
          <div className="gallery-secondary">
            {secondaryImages.map((image, index) => {
              const imageIndex = orderedGalleryImages.findIndex(
                (galleryImage) => galleryImage.id === image.id && galleryImage.url === image.url,
              )

              return (
                <button
                  className="gallery-image-button"
                  key={image.id ?? image.url}
                  type="button"
                  onClick={() => openLightbox(imageIndex)}
                  aria-label={`Open ${title} gallery image ${index + 2}`}
                >
                  <img
                    src={resolveAssetUrl(image.medium_url ?? image.large_url ?? image.url)}
                    srcSet={buildSrcSet(image)}
                    sizes="(max-width: 767px) 100vw, (max-width: 1280px) 33vw, 480px"
                    alt={`${title} gallery ${index + 1}`}
                    loading="lazy"
                    decoding="async"
                  />
                </button>
              )
            })}
          </div>
        ) : null}
        {orderedGalleryImages.length > 1 ? (
          <span className="mobile-gallery-counter">{currentMobileImage + 1} / {orderedGalleryImages.length}</span>
        ) : null}
        {hasMoreImages ? (
          <button className="gallery-view-all" type="button" onClick={() => openLightbox(0)}>
            View All Photos ({galleryImages.length})
          </button>
        ) : null}
      </div>

      {activeImage ? (
        <div className="image-lightbox" role="dialog" aria-modal="true" aria-label={`${title} image viewer`}>
          <button className="image-lightbox-backdrop" type="button" aria-label="Close image viewer" onClick={closeLightbox} />
          <div className="image-lightbox-panel">
            <button className="image-lightbox-close" type="button" aria-label="Close image viewer" onClick={closeLightbox}>
              <X aria-hidden="true" />
            </button>
            {orderedGalleryImages.length > 1 ? (
              <button
                className="image-lightbox-nav image-lightbox-prev"
                type="button"
                aria-label="Previous image"
                onClick={showPreviousImage}
              >
                <ChevronLeft aria-hidden="true" />
              </button>
            ) : null}
            <img
              src={resolveAssetUrl(activeImage.large_url ?? activeImage.medium_url ?? activeImage.url)}
              alt={`${title} ${activeImageIndex + 1} of ${orderedGalleryImages.length}`}
            />
            {orderedGalleryImages.length > 1 ? (
              <button
                className="image-lightbox-nav image-lightbox-next"
                type="button"
                aria-label="Next image"
                onClick={showNextImage}
              >
                <ChevronRight aria-hidden="true" />
              </button>
            ) : null}
            <div className="image-lightbox-counter" aria-live="polite">
              {activeImageIndex + 1} / {orderedGalleryImages.length}
            </div>
          </div>
        </div>
      ) : null}
    </>
  )
}

export default ImageGallery

function buildSrcSet(image) {
  return [
    image.thumbnail_url ? `${resolveAssetUrl(image.thumbnail_url)} 640w` : null,
    image.medium_url ? `${resolveAssetUrl(image.medium_url)} 960w` : null,
    image.large_url ? `${resolveAssetUrl(image.large_url)} 1440w` : null,
  ]
    .filter(Boolean)
    .join(', ')
}

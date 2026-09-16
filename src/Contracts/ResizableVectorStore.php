<?php

declare(strict_types=1);

namespace Murkrow\FilamentAi\Contracts;

/**
 * A vector store whose column has a fixed width that can be changed in place.
 *
 * Optional on purpose. Adding these methods to VectorStore would break every
 * third-party driver, and a driver without a fixed-width column (the in-memory
 * test store, a remote service) has nothing to resize.
 */
interface ResizableVectorStore
{
    /**
     * The width the vector column actually has in the database -- which is not
     * necessarily `rag.embeddings.dimensions`: the column is created once, by a
     * migration, with whatever the config said at that moment.
     *
     * Null when the column is missing or has no declared width.
     */
    public function installedDimensions(): ?int;

    /**
     * Change the column width. Every stored vector is discarded: a vector of one
     * width cannot be cast to another, and vectors from two models are not
     * comparable anyway. Callers drop the indexes first and re-embed afterwards.
     */
    public function resize(int $dimensions): void;
}

# Media

Screenshot uploads and processing: presigned uploads, checks, cleaning and small WebP variants. It is generic and knows nothing about tickets. It owns the tables `med_object` and `med_upload_intent`. Its Api is `Uploads`, `MediaQueries` and `SignedUrls`. `Infrastructure\Health\ObjectStorageIsReachable` tells `/health/ready` whether the object storage is reachable. It may use the Api of SharedKernel only.

Status: the two tables exist (`Version20261115120000`, with row-level security) and are mapped by `UploadIntent` and `MediaObject`. The domain ports `UploadIntents` and `MediaObjects` are stored through `Infrastructure\Doctrine\DoctrineUploadIntents` and `DoctrineMediaObjects`; `recordUse` lets an upload intent be used once only, even by two requests at once. No Api, use case or route uses them yet.

See the [module catalogue](../../docs/02-architecture/modules.md#media-med_) and the tables in [data-model.md](../../docs/02-architecture/data-model.md#review-and-media).

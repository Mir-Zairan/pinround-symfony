# Media

Screenshot uploads and processing: presigned uploads, checks, cleaning and small WebP variants. It is generic and knows nothing about tickets. It owns the tables `med_object` and `med_upload_intent`. Its Api is `Uploads`, `MediaQueries` and `SignedUrls`. `Infrastructure\Health\ObjectStorageIsReachable` tells `/health/ready` whether the object storage is reachable. It may use the Api of SharedKernel only.

See the [module catalogue](../../docs/02-architecture/modules.md#media-med_).

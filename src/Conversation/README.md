# Conversation

Comments, questions and replies on a ticket. A question does not change the step; the asked person gets the turn. It owns the tables `cnv_message` and `cnv_reply`. Its Api is `ThreadQueries`. It may use the Api of Ticketing, Insight (`TurnProjector::refresh`), Identity, and Organization (the ask voter's `CurrentActor` roles).

Its events, in `Api\Event`, are `CommentPosted`, `QuestionAsked`, `QuestionAnswered`, `ReplyPosted`, `CommentResolved`, `MessageEdited` and `MessageRemoved` (`ConversationEvent`, ids only, through the outbox to `async_notify`).

Its tables are built (`Version20261114120000`): `cnv_message` mapped by `Domain\Message` and `cnv_reply` by `Domain\Reply`, tenant tables with row-level security that go with their ticket (ON DELETE CASCADE); see the [data model](../../docs/02-architecture/data-model.md#conversation).

See the [module catalogue](../../docs/02-architecture/modules.md#conversation-cnv_).

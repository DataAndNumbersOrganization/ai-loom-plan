I have a Loom video walkthrough that needs to be turned into admin-facing documentation for our team.{{ $screenshotLine }}

Read the transcript and context file below, then write clear, friendly documentation in Markdown format. This documentation will be read by operations staff, customer support, and non-developer admins — so avoid technical jargon, code references, and implementation details. Focus on **what** the feature does, **how** to use it step-by-step, and **why** it matters.

Structure the documentation with:
- A clear title
- A brief overview (1–2 sentences explaining what this feature/process is for)
- Step-by-step instructions with numbered lists where appropriate
- Tips or notes for common questions or gotchas
- Use simple, plain language throughout

Once you've written the documentation:

1. Save the markdown content to a file at `{{ $planPath }}`
2. Seed it into the admin documentation system by running:

```
php artisan documents:upsert "YOUR DOCUMENT TITLE" \
    --description="A brief one-line summary of what this document covers" \
    --content-file={{ $planPath }} \
    --tags=tag1,tag2,tag3 \
    --route-patterns=onyx/relevant-section,onyx/relevant-section/*
```

The command will output a review URL where the document can be previewed in the admin panel.

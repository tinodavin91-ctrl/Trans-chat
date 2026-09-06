# Firebase setup for chat-api

This API now stores application data in **Cloud Firestore** instead of MySQL.

## Database name

In the Firebase console the database may appear as **Trans chat**.  
Firestore database IDs cannot contain spaces, so the app connects with:

```env
FIREBASE_FIRESTORE_DATABASE=trans-chat
```

If your console shows a different ID under Firestore → Databases, use that exact ID.

## Credentials

1. Open [Firebase Console](https://console.firebase.google.com/) → your project
2. Project settings → **Service accounts**
3. Generate a new private key (JSON)
4. Save it as:

```text
storage/app/firebase-credentials.json
```

5. Confirm `.env` contains:

```env
FIREBASE_CREDENTIALS=storage/app/firebase-credentials.json
FIREBASE_FIRESTORE_DATABASE=trans-chat
```

## Collections used

| Collection | Purpose |
|---|---|
| `users` | Accounts |
| `personal_access_tokens` | API bearer tokens |
| `conversations` | Direct / group chats |
| `conversation_participants` | Chat membership |
| `messages` | Chat messages |
| `message_reactions` | Emoji reactions |
| `blocks` | User blocks |
| `bug_reports` | Bug reports |
| `sticker_packs` | Sticker packs |
| `stickers` | Stickers |

Session, cache, and queue drivers use the filesystem / sync so Laravel no longer needs MySQL for app data.

Realtime broadcasting still uses Laravel Reverb.

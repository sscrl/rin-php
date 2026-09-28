import {
  SettingsBadge,
  SettingsCard,
  SettingsCardBody,
  SettingsCardHeader,
  SettingsCardRow,
} from "@rin/ui";
import { useCallback, useEffect, useState, type ReactNode } from "react";
import { useTranslation } from "react-i18next";
import type { Friend } from "@rin/api";
import { client } from "../app/runtime";
import { Button } from "../components/button";
import { useAlert, useConfirm } from "../components/dialog";

export function FriendModerationSettings() {
  const { t } = useTranslation();
  const [friends, setFriends] = useState<Friend[]>([]);
  const [loading, setLoading] = useState(true);
  const [busyId, setBusyId] = useState<number | null>(null);
  const { showAlert, AlertUI } = useAlert();
  const { showConfirm, ConfirmUI } = useConfirm();

  const loadFriends = useCallback(async () => {
    const { data, error } = await client.friend.list();
    if (error) {
      showAlert(error.value as string);
      return;
    }
    setFriends(data?.friend_list ?? []);
  }, [showAlert]);

  useEffect(() => {
    void loadFriends().finally(() => {
      setLoading(false);
    });
  }, [loadFriends]);

  const pending = friends.filter((friend) => friend.accepted === 0);
  const blocked = friends.filter((friend) => friend.accepted === -1);

  async function setAccepted(friend: Friend, accepted: number) {
    setBusyId(friend.id);
    const { error } = await client.friend.update(friend.id, {
      name: friend.name,
      desc: friend.desc ?? "",
      avatar: friend.avatar,
      url: friend.url,
      accepted,
      sort_order: friend.sort_order ?? 0,
    });
    setBusyId(null);
    if (error) {
      showAlert(error.value as string);
      return;
    }
    await loadFriends();
  }

  function deleteFriend(friend: Friend) {
    showConfirm(t("delete.title"), t("delete.confirm"), async () => {
      setBusyId(friend.id);
      const { error } = await client.friend.delete(friend.id);
      setBusyId(null);
      if (error) {
        showAlert(error.value as string);
        return;
      }
      await loadFriends();
    });
  }

  return (
    <>
      <FriendStatusList
        title={t("settings.friend.pending.title")}
        description={t("settings.friend.pending.desc")}
        friends={pending}
        loading={loading}
        busyId={busyId}
        badgeCount={pending.length}
        actions={(friend) => (
          <>
            <Button
              title={t("friends.review.accepted")}
              disabled={busyId === friend.id}
              onClick={() => {
                void setAccepted(friend, 1);
              }}
            />
            <Button
              secondary
              title={t("friends.review.rejected")}
              disabled={busyId === friend.id}
              onClick={() => {
                void setAccepted(friend, -1);
              }}
            />
          </>
        )}
      />
      <FriendStatusList
        title={t("settings.friend.blocked.title")}
        description={t("settings.friend.blocked.desc")}
        friends={blocked}
        loading={loading}
        busyId={busyId}
        badgeCount={blocked.length}
        tone="danger"
        actions={(friend) => (
          <>
            <Button
              title={t("friends.review.accepted")}
              disabled={busyId === friend.id}
              onClick={() => {
                void setAccepted(friend, 1);
              }}
            />
            <Button
              secondary
              title={t("delete.title")}
              disabled={busyId === friend.id}
              onClick={() => {
                deleteFriend(friend);
              }}
            />
          </>
        )}
      />
      <AlertUI />
      <ConfirmUI />
    </>
  );
}

function FriendStatusList({
  title,
  description,
  friends,
  loading,
  busyId,
  badgeCount,
  tone = "default",
  actions,
}: {
  title: string;
  description: string;
  friends: Friend[];
  loading: boolean;
  busyId: number | null;
  badgeCount: number;
  tone?: "default" | "danger";
  actions: (friend: Friend) => ReactNode;
}) {
  if (loading || friends.length === 0) {
    return null;
  }

  return (
    <SettingsCard tone={tone}>
      <SettingsCardRow
        header={
          <SettingsCardHeader
            title={title}
            description={description}
            badge={<SettingsBadge>{badgeCount}</SettingsBadge>}
          />
        }
        action={null}
      />
      <SettingsCardBody>
        <div className="flex flex-col">
          {friends.map((friend) => (
            <div
              key={friend.id}
              className="flex flex-col gap-3 border-b border-black/5 py-4 last:border-b-0 last:pb-0 first:pt-0 dark:border-white/5 md:flex-row md:items-center"
            >
              <div className="flex min-w-0 flex-1 items-start gap-3">
                <img
                  src={friend.avatar}
                  alt={friend.name}
                  className={`h-10 w-10 shrink-0 rounded-full object-cover ${busyId === friend.id ? "opacity-50" : ""}`}
                />
                <div className="min-w-0">
                  <p className="truncate text-sm font-medium t-primary">{friend.name}</p>
                  <a
                    href={friend.url}
                    target="_blank"
                    rel="noreferrer"
                    className="block truncate text-sm text-neutral-500 hover:text-theme dark:text-neutral-400"
                  >
                    {friend.url}
                  </a>
                  {friend.desc ? (
                    <p className="mt-1 line-clamp-2 text-sm text-neutral-500 dark:text-neutral-400">{friend.desc}</p>
                  ) : null}
                </div>
              </div>
              <div className="flex shrink-0 flex-wrap items-center gap-2 md:justify-end">{actions(friend)}</div>
            </div>
          ))}
        </div>
      </SettingsCardBody>
    </SettingsCard>
  );
}

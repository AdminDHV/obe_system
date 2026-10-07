import React from 'react';
import { Link, usePage, router } from '@inertiajs/react';
import {
  Sidebar,
  SidebarContent,
  SidebarFooter,
  SidebarGroup,
  SidebarGroupContent,
  SidebarGroupLabel,
  SidebarHeader,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
  SidebarRail,
} from '@/components/ui/sidebar';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { ChevronUp, ChevronDown, LogOut, User } from 'lucide-react';
import { getNavigationItems } from '@/config/navigation';
import { cn } from '@/lib/utils';
import { roleLabels } from '@/lib/roles';

export function AppSidebar() {
  const { auth } = usePage().props;
  const user = auth.user;
  const navigationGroups = getNavigationItems(user);

  // `manager.reports.*` cũng khớp `manager.reports.course.*` — để mỗi trang chỉ sáng một mục,
  // mục nào khớp sát nhất (mẫu dài nhất) mới được tính là đang mở.
  const patternOf = (routeName) => routeName.replace(/\.index$/, '.*');
  const activeRouteName = navigationGroups
    .flatMap((group) => group.items)
    .map((item) => item.routeName)
    .filter((routeName) => routeName && route().current(patternOf(routeName)))
    .sort((a, b) => patternOf(b).length - patternOf(a).length)[0] ?? null;

  const initials = user.name
    ? user.name.split(' ').map((n) => n[0]).join('').toUpperCase().slice(0, 2)
    : '??';

  const handleLogout = (e) => {
    e.preventDefault();
    router.post(route('logout'));
  };

  const renderItem = (item, subline) => {
    const routeName = item.routeName;
    const href = routeName ? route(routeName) : item.href;
    const isActive = !!routeName && routeName === activeRouteName;
    return (
      <SidebarMenuItem key={href || routeName}>
        <SidebarMenuButton
          asChild
          isActive={isActive}
          tooltip={item.description ? `${item.label} — ${item.description}` : item.label}
          className={cn(
            'relative px-4 rounded-lg transition-all duration-150',
            subline ? 'h-auto py-2.5 items-start' : 'h-12 py-3 text-base font-medium',
            isActive
              ? 'bg-sidebar-accent text-sidebar-accent-foreground shadow-sm'
              : 'text-sidebar-foreground/80 hover:bg-sidebar-accent/80 hover:text-sidebar-accent-foreground'
          )}
        >
          <Link href={href}>
            {isActive && (
              <span className="absolute left-0 top-1/2 -translate-y-1/2 h-5 w-1 rounded-full bg-primary" />
            )}
            <item.icon className="size-5 shrink-0" />
            {subline ? (
              <span className="flex min-w-0 flex-col gap-0.5">
                <span className="text-base font-medium leading-tight">{item.label}</span>
                <span className="truncate text-[11px] font-normal leading-tight text-sidebar-foreground/45">
                  {item.description}
                </span>
              </span>
            ) : (
              <span>{item.label}</span>
            )}
          </Link>
        </SidebarMenuButton>
      </SidebarMenuItem>
    );
  };

  const groupLabelClass =
    'px-3 py-3 text-xs font-bold uppercase tracking-[0.1em] text-sidebar-foreground/40';

  return (
    <Sidebar variant="inset">
      <SidebarHeader className="border-b border-sidebar-border/50 pb-3">
        <SidebarMenu>
          <SidebarMenuItem>
            <SidebarMenuButton size="lg" asChild>
              <Link href="/">
                <div className="flex items-center gap-2 py-1">
                  <img
                    src="/dhv-logo.png"
                    alt="DHV University"
                    className="h-8 w-auto max-w-[64px] shrink-0 brightness-0 invert"
                  />
                  <div className="grid min-w-0 flex-1 text-left text-sm leading-tight">
                    <span className="truncate font-semibold text-white">OBE System</span>
                    <span className="truncate text-xs text-sidebar-foreground/60">
                      Đại học Hùng Vương
                    </span>
                  </div>
                </div>
              </Link>
            </SidebarMenuButton>
          </SidebarMenuItem>
        </SidebarMenu>
      </SidebarHeader>

      <SidebarContent className="py-4 [scrollbar-width:thin] [scrollbar-color:rgba(255,255,255,0.18)_transparent] [&::-webkit-scrollbar]:w-1.5 [&::-webkit-scrollbar-track]:bg-transparent [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-thumb]:bg-white/15 hover:[&::-webkit-scrollbar-thumb]:bg-white/25">
        {navigationGroups.map((group) => {
          const showSubline = !group.collapsible;
          const menu = (
            <SidebarMenu className="gap-1">
              {group.items.map((item) => renderItem(item, showSubline && !!item.description))}
            </SidebarMenu>
          );

          if (group.collapsible) {
            return (
              <Collapsible
                key={group.label}
                defaultOpen={group.defaultOpen}
                className="group/collapsible"
              >
                <SidebarGroup>
                  <SidebarGroupLabel asChild className={groupLabelClass}>
                    <CollapsibleTrigger className="flex w-full items-center justify-between transition-colors hover:text-sidebar-foreground/70">
                      {group.label}
                      <ChevronDown className="size-4 transition-transform duration-200 group-data-[state=open]/collapsible:rotate-180" />
                    </CollapsibleTrigger>
                  </SidebarGroupLabel>
                  <CollapsibleContent>
                    <SidebarGroupContent>{menu}</SidebarGroupContent>
                  </CollapsibleContent>
                </SidebarGroup>
              </Collapsible>
            );
          }

          return (
            <SidebarGroup key={group.label}>
              <SidebarGroupLabel className={groupLabelClass}>{group.label}</SidebarGroupLabel>
              <SidebarGroupContent>{menu}</SidebarGroupContent>
            </SidebarGroup>
          );
        })}
      </SidebarContent>

      <SidebarFooter className="border-t border-sidebar-border/50 pt-2">
        <SidebarMenu>
          <SidebarMenuItem>
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <SidebarMenuButton
                  size="lg"
                  className="h-12 px-3 data-[state=open]:bg-sidebar-accent/50"
                >
                  <Avatar className="size-8 rounded-lg ring-2 ring-sidebar-border">
                    <AvatarFallback className="rounded-lg bg-primary/80 text-primary-foreground text-xs font-bold">
                      {initials}
                    </AvatarFallback>
                  </Avatar>
                  <div className="grid flex-1 text-left text-sm leading-tight">
                    <span className="truncate font-semibold text-sidebar-foreground">{user.name}</span>
                    {/* Vai trò thay cho email: người dùng biết email của mình rồi, còn "mình đang
                        ở vai trò nào" mới là thứ quyết định họ làm được gì. Người xem (cố vấn học
                        tập) chỉ đọc — không nói ra thì họ tưởng hệ thống hỏng khi bấm Lưu không được. */}
                    <span className="truncate text-xs text-sidebar-foreground/50" title={user.email}>
                      {roleLabels(user.roles) || user.email}
                    </span>
                  </div>
                  <ChevronUp className="ml-auto size-4 text-sidebar-foreground/50" />
                </SidebarMenuButton>
              </DropdownMenuTrigger>
              <DropdownMenuContent
                side="top"
                className="w-[--radix-dropdown-menu-trigger-width] min-w-56"
              >
                <DropdownMenuItem asChild>
                  <Link href={route('profile.edit')}>
                    <User className="mr-2 size-4" />
                    <span>Profile</span>
                  </Link>
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem onClick={handleLogout}>
                  <LogOut className="mr-2 size-4" />
                  <span>Đăng xuất</span>
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </SidebarMenuItem>
        </SidebarMenu>
      </SidebarFooter>

      <SidebarRail />
    </Sidebar>
  );
}

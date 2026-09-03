<?php
/**
 * PEPP Learning ERP - WhatsApp Inbox Page.
 */

require_once 'includes/auth.php';
require_once 'config/database.php';
require_permission('whatsapp-inbox');

$active_page = 'whatsapp-inbox';
$page_title  = 'WhatsApp Inbox';
$page_sub    = 'Read and reply to student incoming WhatsApp messages';

// Fetch approved templates for selection in template dropdown
$approvedTemplates = [];
try {
    $stmtTpl = $pdo->query("SELECT template_name FROM communication_templates WHERE channel = 'whatsapp' AND status = 'approved' ORDER BY template_name ASC");
    $approvedTemplates = $stmtTpl->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

include 'includes/admin_nav.php';
?>

<div style="display: flex; height: calc(100vh - 120px); min-height: 500px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 16px; overflow: hidden; margin-top: 10px;">

    <!-- LEFT PANEL: Conversations List -->
    <div style="width: 320px; border-right: 1px solid #e2e8f0; display: flex; flex-direction: column; background: #fff; flex-shrink: 0;">
        <!-- Search and Tabs -->
        <div style="padding: 16px; border-bottom: 1px solid #e2e8f0; display: flex; flex-direction: column; gap: 12px;">
            <div style="position: relative;">
                <i class="fas fa-search" style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 0.85rem;"></i>
                <input type="text" id="search-input" oninput="loadConversations()" placeholder="Search name, phone, UID..." style="width: 100%; padding: 8px 12px 8px 36px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 0.85rem; outline: none; transition: border-color 0.2s;" onfocus="this.style.borderColor='#6366f1'" onblur="this.style.borderColor='#cbd5e1'">
            </div>

            <div style="display: flex; gap: 4px; background: #f1f5f9; padding: 4px; border-radius: 8px;">
                <button onclick="switchTab('all', this)" class="tab-btn active" style="flex: 1; padding: 6px; border: none; background: transparent; border-radius: 6px; font-size: 0.75rem; font-weight: 600; cursor: pointer; color: #475569; transition: all 0.2s;">All</button>
                <button onclick="switchTab('unread', this)" class="tab-btn" style="flex: 1; padding: 6px; border: none; background: transparent; border-radius: 6px; font-size: 0.75rem; font-weight: 600; cursor: pointer; color: #475569; transition: all 0.2s;">Unread</button>
                <button onclick="switchTab('students', this)" class="tab-btn" style="flex: 1; padding: 6px; border: none; background: transparent; border-radius: 6px; font-size: 0.75rem; font-weight: 600; cursor: pointer; color: #475569; transition: all 0.2s;">Students</button>
                <button onclick="switchTab('unknown', this)" class="tab-btn" style="flex: 1; padding: 6px; border: none; background: transparent; border-radius: 6px; font-size: 0.75rem; font-weight: 600; cursor: pointer; color: #475569; transition: all 0.2s;">Unknown</button>
            </div>
        </div>

        <!-- Conversations Scroll -->
        <div id="convs-list" style="flex: 1; overflow-y: auto; display: flex; flex-direction: column;">
            <!-- Rendered dynamically -->
            <div style="padding: 20px; text-align: center; color: #94a3b8; font-size: 0.85rem;">
                <i class="fas fa-spinner fa-spin" style="margin-bottom: 8px; display: block; font-size: 1.2rem;"></i> Loading conversations...
            </div>
        </div>
    </div>

    <!-- CENTER PANEL: Chat Thread -->
    <div style="flex: 1; display: flex; flex-direction: column; background: #f8fafc; position: relative;">
        <!-- Thread Header -->
        <div id="chat-header" style="height: 64px; border-bottom: 1px solid #e2e8f0; background: #fff; display: flex; align-items: center; justify-content: space-between; padding: 0 20px; flex-shrink: 0;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="width: 36px; height: 36px; border-radius: 50%; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.95rem;" id="chat-avatar">C</div>
                <div>
                    <div style="font-weight: 700; color: #1e293b; font-size: 0.9rem;" id="chat-contact-name">Select a Conversation</div>
                    <div style="font-size: 0.75rem; color: #64748b;" id="chat-phone">No thread selected</div>
                </div>
            </div>
            <div id="24h-status-badge"></div>
        </div>

        <!-- Messages Scroll Area -->
        <div id="messages-body" style="flex: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; gap: 12px;">
            <div style="margin: auto; text-align: center; color: #94a3b8; font-size: 0.85rem;">
                <i class="fab fa-whatsapp" style="font-size: 3rem; color: #10b981; margin-bottom: 12px; display: block; opacity: 0.5;"></i>
                Select a conversation from the sidebar to view chat history.
            </div>
        </div>

        <!-- Input / Reply Bar -->
        <div id="reply-container" style="border-top: 1px solid #e2e8f0; background: #fff; padding: 16px; display: none; flex-direction: column; gap: 10px; flex-shrink: 0;">
            <!-- Meta 24 Hour Warning and Template Selector -->
            <div id="twentyfour-hour-warning" style="display: none; flex-direction: column; gap: 12px; background: #fffdf5; border: 1.5px solid #fef3c7; border-radius: 12px; padding: 14px 16px; box-shadow: 0 2px 6px rgba(245, 158, 11, 0.06);">
                <!-- Notice Header Banner -->
                <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; padding-bottom: 12px; border-bottom: 1px dashed #fde68a;">
                    <div style="display: flex; align-items: flex-start; gap: 10px;">
                        <div style="width: 34px; height: 34px; border-radius: 10px; background: #fef3c7; color: #d97706; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 1rem; border: 1px solid #fde68a;">
                            <i class="fas fa-clock-rotate-left"></i>
                        </div>
                        <div>
                            <div style="font-weight: 700; color: #92400e; font-size: 0.85rem; display: flex; align-items: center; gap: 6px;">
                                24-Hour WhatsApp Messaging Window Expired
                            </div>
                            <div style="font-size: 0.75rem; color: #b45309; line-height: 1.45; margin-top: 2px;">
                                Free-form messaging is locked by Meta WhatsApp policy. You can re-open conversation access by sending a pre-approved template message.
                            </div>
                        </div>
                    </div>
                    <span style="background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; font-size: 0.68rem; font-weight: 700; padding: 3px 9px; border-radius: 20px; white-space: nowrap; display: inline-flex; align-items: center; gap: 5px; flex-shrink: 0; letter-spacing: 0.2px;">
                        <i class="fas fa-lock" style="font-size: 0.65rem;"></i> Free Text Locked
                    </span>
                </div>

                <!-- Template Selector Form Group -->
                <div style="display: flex; flex-direction: column; gap: 7px;">
                    <!-- Dropdown Label & Meta Badge -->
                    <div style="display: flex; align-items: center; justify-content: space-between;">
                        <label for="template-select" style="font-weight: 700; color: #1e293b; font-size: 0.82rem; display: inline-flex; align-items: center; gap: 6px; cursor: pointer; margin: 0;">
                            <i class="fab fa-whatsapp" style="color: #22c55e; font-size: 1rem;"></i>
                            <span>Select Approved WhatsApp Template</span>
                            <span style="font-size: 0.68rem; font-weight: 600; color: #4338ca; background: #e0e7ff; border: 1px solid #c7d2fe; padding: 1px 7px; border-radius: 6px; letter-spacing: 0.2px;">Meta Approved</span>
                        </label>
                        <span style="font-size: 0.72rem; color: #64748b; font-weight: 500;">
                            <i class="fas fa-layer-group" style="color: #94a3b8; margin-right: 3px;"></i> <?php echo count($approvedTemplates); ?> templates available
                        </span>
                    </div>

                    <!-- Custom Styled Select Input Wrapper -->
                    <div class="template-select-wrapper" style="position: relative; width: 100%;">
                        <div class="template-select-icon" style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #64748b; font-size: 0.85rem; pointer-events: none; z-index: 1;">
                            <i class="fas fa-file-lines"></i>
                        </div>
                        <select id="template-select" onchange="onTemplateSelectChange(this.value)" class="custom-template-select" style="width: 100%; height: 42px; padding: 0 40px 0 38px; border-radius: 8px; border: 1.5px solid #cbd5e1; background-color: #ffffff; color: #1e293b; font-size: 0.84rem; font-weight: 600; outline: none; transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); cursor: pointer; appearance: none; -webkit-appearance: none; -moz-appearance: none; box-shadow: 0 1px 2px rgba(0,0,0,0.04);">
                            <option value="">— Select an Approved WhatsApp Template —</option>
                            <?php foreach ($approvedTemplates as $tplName): ?>
                                <option value="<?php echo htmlspecialchars($tplName); ?>"><?php echo htmlspecialchars($tplName); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="template-select-chevron" style="position: absolute; right: 14px; top: 50%; transform: translateY(-50%); color: #64748b; font-size: 0.75rem; pointer-events: none; z-index: 1;">
                            <i class="fas fa-chevron-down"></i>
                        </div>
                    </div>

                    <!-- Helper Subtext -->
                    <div style="font-size: 0.72rem; color: #64748b; display: flex; align-items: center; gap: 5px; margin-top: 1px;">
                        <i class="fas fa-circle-info" style="color: #6366f1; font-size: 0.75rem;"></i>
                        <span>Variables mapping and live student preview will resolve automatically upon selection.</span>
                    </div>
                </div>

            </div>

            <!-- Template Variables Mapping Visualizer (Safeguard 3) -->
            <div id="template-variables-preview" style="display: none; background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 14px 16px; font-size: 0.75rem; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; border-bottom: 1px solid #f1f5f9; padding-bottom: 6px;">
                    <h5 style="margin: 0; font-weight: 700; color: #334155; font-size: 0.8rem; display: flex; align-items: center; gap: 6px;">
                        <i class="fas fa-code-branch" style="color: #6366f1;"></i> Variables Mapping &amp; Live Resolution
                    </h5>
                    <span style="font-size: 0.68rem; color: #059669; font-weight: 600; background: #ecfdf5; border: 1px solid #a7f3d0; padding: 1px 7px; border-radius: 10px;">
                        <i class="fas fa-check-circle" style="font-size: 0.65rem;"></i> Auto-Resolved
                    </span>
                </div>
                <div id="variables-list" style="display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px;"></div>

                <div style="display: flex; align-items: center; justify-content: space-between; margin: 10px 0 6px;">
                    <h5 style="margin: 0; font-weight: 700; color: #334155; font-size: 0.8rem; display: flex; align-items: center; gap: 6px;">
                        <i class="fab fa-whatsapp" style="color: #22c55e;"></i> WhatsApp Message Preview
                    </h5>
                    <span style="font-size: 0.68rem; color: #64748b;">Live Student Context Preview</span>
                </div>
                <div id="template-text-preview" style="background: #e7fed9; border: 1px solid #bbf7d0; color: #1e293b; padding: 12px 14px; border-radius: 12px; border-top-left-radius: 2px; font-size: 0.82rem; line-height: 1.5; white-space: pre-wrap; box-shadow: 0 1px 2px rgba(0,0,0,0.04);"></div>
            </div>

            <!-- Free text message inputs / Template send action -->
            <div style="display: flex; gap: 10px; align-items: flex-end;" id="free-text-input-row">
                <textarea id="reply-textarea" placeholder="Type a message..." rows="1" style="flex: 1; border: 1px solid #cbd5e1; border-radius: 8px; padding: 8px 12px; font-size: 0.85rem; outline: none; resize: none; min-height: 38px; max-height: 120px;" oninput="adjustTextareaHeight(this)"></textarea>
                <button id="send-reply-btn" onclick="sendReply()" class="btn btn-primary" style="padding: 9px 18px; border-radius: 8px; display: flex; align-items: center; gap: 6px; height: 38px; font-weight: 600;">
                    <i class="fas fa-paper-plane"></i> Send
                </button>
            </div>
        </div>
    </div>

    <!-- RIGHT PANEL: Student Context -->
    <div id="student-context-panel" style="width: 280px; border-left: 1px solid #e2e8f0; background: #fff; display: none; flex-direction: column; overflow-y: auto; flex-shrink: 0; padding: 20px;">
        <h4 style="margin: 0 0 16px; font-weight: 700; color: #1e293b; font-size: 0.95rem; border-bottom: 1px solid #f1f5f9; padding-bottom: 8px;">
            <i class="fas fa-user-circle" style="color: #6366f1; margin-right: 4px;"></i> Student Context
        </h4>
        <div id="student-context-content" style="display: flex; flex-direction: column; gap: 14px; font-size: 0.8rem;">
            <!-- Rendered dynamically -->
        </div>
    </div>
</div>

<!-- Lightbox Modal -->
<div id="image-lightbox-modal" style="display: none; position: fixed; inset: 0; background: rgba(15, 23, 42, 0.9); z-index: 9999; align-items: center; justify-content: center; padding: 20px; backdrop-filter: blur(4px);">
    <div style="position: relative; max-width: 90vw; max-height: 90vh; display: flex; flex-direction: column; align-items: center; justify-content: center;">
        <button onclick="closeImageLightbox()" style="position: absolute; top: -45px; right: 0; background: none; border: none; color: #fff; font-size: 2rem; cursor: pointer; transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.1)'" onmouseout="this.style.transform='scale(1)'" title="Close (Esc)"><i class="fas fa-times"></i></button>
        <img id="lightbox-image" src="" alt="Lightbox View" style="max-width: 100%; max-height: 80vh; object-fit: contain; border-radius: 12px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5); border: 1px solid rgba(255,255,255,0.1);">
        <div style="margin-top: 16px;">
            <a id="lightbox-download" href="" target="_blank" class="btn btn-primary" style="padding: 8px 18px; border-radius: 8px; font-size: 0.8rem; display: inline-flex; align-items: center; gap: 8px; background: #6366f1; border-color: #6366f1; color: #fff; text-decoration: none; font-weight: 600; box-shadow: 0 4px 6px -1px rgba(99, 102, 241, 0.2);"><i class="fas fa-download"></i> Download Full Image</a>
        </div>
    </div>
</div>

<style>
.tab-btn.active {
    background: #fff !important;
    color: #4f46e5 !important;
    box-shadow: 0 1px 2px rgba(0,0,0,0.05);
}
.conv-item {
    padding: 12px 16px;
    border-bottom: 1px solid #f1f5f9;
    cursor: pointer;
    transition: background 0.2s;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.conv-item:hover {
    background: #f8fafc;
}
.conv-item.active {
    background: #e0e7ff;
}
.bubble {
    max-width: 70%;
    padding: 10px 14px;
    border-radius: 12px;
    font-size: 0.85rem;
    line-height: 1.4;
    word-break: break-word;
    position: relative;
    box-shadow: 0 1px 2px rgba(0,0,0,0.05);
}
.bubble.inbound {
    align-self: flex-start;
    background: #fff;
    color: #1e293b;
    border-bottom-left-radius: 2px;
    border: 1px solid #e2e8f0;
}
.bubble.outbound {
    align-self: flex-end;
    background: #e0e7ff;
    color: #1e293b;
    border-bottom-right-radius: 2px;
    border: 1px solid #c7d2fe;
}
.status-tick {
    font-size: 0.7rem;
    margin-left: 6px;
    color: #94a3b8;
}
.status-tick.read {
    color: #3b82f6;
}
.status-tick.failed {
    color: #ef4444;
}
.bubble.outbound.failed {
    background: #fef2f2 !important;
    color: #1e293b !important;
    border-color: #fca5a5 !important;
}
.custom-template-select:hover {
    border-color: #94a3b8 !important;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}
.custom-template-select:focus {
    border-color: #6366f1 !important;
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.18) !important;
    background-color: #ffffff !important;
}
.template-select-wrapper:hover i.fa-file-lines {
    color: #4f46e5 !important;
}
.template-select-wrapper:hover i.fa-chevron-down {
    color: #1e293b !important;
}
</style>

<script>
function isPermanentError(errMsg) {
    if (!errMsg) return false;
    const lower = errMsg.toLowerCase();
    const codeMatch = errMsg.match(/\[Meta Code (\d+)\]/);
    if (codeMatch) {
        const code = parseInt(codeMatch[1], 10);
        if ([131026, 131053, 131047, 131045, 131051, 131052, 100, 190].includes(code)) {
            return true;
        }
        if ([131021, 131048, 429].includes(code)) {
            return false;
        }
    }
    return (
        lower.includes('healthy ecosystem engagement') ||
        lower.includes('131026') ||
        lower.includes('policy') ||
        lower.includes('not in allowed list') ||
        lower.includes('invalid phone number') ||
        lower.includes('does not exist') ||
        lower.includes('recipient') ||
        lower.includes('undeliverable') ||
        lower.includes('not a whatsapp number') ||
        lower.includes('parameter') ||
        lower.includes('not approved')
    );
}

let currentFilter = 'all';
let currentConversationId = null;
let currentStudentUid = null;
let conversationsData = [];
let csrfToken = '<?php echo csrf_token(); ?>';
document.addEventListener('DOMContentLoaded', () => {
    loadConversations();
    // Poll for new messages every 5 seconds
    setInterval(() => {
        loadConversations(true);
        if (currentConversationId) {
            loadMessages(currentConversationId, true);
        }
    }, 5000);
});

function switchTab(filter, btn) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    currentFilter = filter;
    loadConversations();
}

function loadConversations(isBackground = false) {
    const search = document.getElementById('search-input').value;
    fetch(`api/v1/communication/fetch-conversations.php?filter=${currentFilter}&search=${encodeURIComponent(search)}`)
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                conversationsData = res.conversations;
                renderConversations(isBackground);
            }
        });
}

function renderConversations(isBackground) {
    const container = document.getElementById('convs-list');
    if (conversationsData.length === 0) {
        container.innerHTML = '<div style="padding: 20px; text-align: center; color: #94a3b8; font-size: 0.8rem;">No conversations found.</div>';
        return;
    }

    let html = '';
    conversationsData.forEach(c => {
        const isActive = c.id == currentConversationId ? 'active' : '';
        const unreadBadge = c.unread_count > 0 ? `<span style="background:#ef4444; color:#fff; font-size:0.7rem; font-weight:700; padding:2px 6px; border-radius:10px; margin-left:auto;">${c.unread_count}</span>` : '';
        const nameInitial = (c.contact_name || 'U').charAt(0).toUpperCase();

        let lastMsgSnippet = c.last_message_text || '';
        if (lastMsgSnippet.length > 35) lastMsgSnippet = lastMsgSnippet.substring(0, 35) + '...';

        const isFailed = c.latest_message_direction === 'outbound' && c.latest_message_status === 'failed';
        if (isFailed) {
            lastMsgSnippet = `<span style="color:#ef4444; font-weight:600;"><i class="fas fa-circle-exclamation" style="font-size:0.7rem;"></i> Delivery failed</span>`;
        }

        const dateObj = new Date(c.last_message_at);
        const dateStr = dateObj.toLocaleDateString([], {day:'2-digit', month:'short'});
        const timeStr = dateObj.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
        const dateTimeStr = dateStr + ', ' + timeStr;

        html += `
            <div class="conv-item ${isActive}" onclick="selectConversation(${c.id}, '${c.student_uid}', '${c.wa_phone_number}')">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div style="width: 32px; height: 32px; border-radius: 50%; background: #f1f5f9; color: #475569; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.8rem;">${nameInitial}</div>
                    <div style="flex: 1; min-width: 0;">
                        <div style="display:flex; justify-content:space-between; align-items:center; gap:4px;">
                            <span style="font-weight: 700; color: #1e293b; font-size: 0.85rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: inline-flex; align-items: center; gap: 6px;">
                                ${c.contact_name || 'Unknown Contact'}
                                ${isFailed ? `<span style="background:#fee2e2; color:#ef4444; border:1px solid #fecaca; font-size:0.6rem; font-weight:800; padding:1px 5px; border-radius:4px; line-height:1.2; text-transform:uppercase;">Failed</span>` : ''}
                            </span>
                            <span style="font-size: 0.65rem; color: #94a3b8; white-space: nowrap;">${dateTimeStr}</span>
                        </div>
                        <div style="display: flex; align-items: center; justify-content: space-between; gap:4px; margin-top: 2px;">
                            <span style="font-size: 0.75rem; color: #64748b; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; width: 100%;">${lastMsgSnippet}</span>
                            ${unreadBadge}
                        </div>
                    </div>
                </div>
            </div>
        `;
    });
    container.innerHTML = html;
}

function selectConversation(id, studentUid, waPhone) {
    currentConversationId = id;
    currentStudentUid = studentUid;

    // Highlight conversation item
    document.querySelectorAll('.conv-item').forEach(el => el.classList.remove('active'));

    loadMessages(id);
    loadStudentContext(studentUid);
}

function loadMessages(id, isBackground = false) {
    const markRead = isBackground ? 0 : 1;
    fetch(`api/v1/communication/fetch-messages.php?conversation_id=${id}&mark_read=${markRead}`)
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                renderMessages(res.messages, isBackground);
                updateWindowPolicies();
            }
        });
}

function renderMessages(messages, isBackground) {
    const container = document.getElementById('messages-body');

    // Find conversation details
    const conv = conversationsData.find(c => c.id == currentConversationId);
    if (!conv) return;

    // Header updates
    document.getElementById('chat-contact-name').innerText = conv.contact_name || 'Unknown Contact';
    document.getElementById('chat-phone').innerText = '+' + conv.wa_phone_number + (conv.student_uid ? ` (UID: ${conv.student_uid})` : '');
    document.getElementById('chat-avatar').innerText = (conv.contact_name || 'U').charAt(0).toUpperCase();

    let html = '';
    messages.forEach(m => {
        const isOut = m.direction === 'outbound';
        const isFailed = isOut && m.status === 'failed' && isPermanentError(m.failure_reason);
        const bubbleClass = isOut ? (isFailed ? 'outbound failed' : 'outbound') : 'inbound';

        let statusTick = '';
        if (isOut) {
            if (m.status === 'read') {
                statusTick = '<i class="fas fa-check-double status-tick read" title="Read"></i>';
            } else if (m.status === 'delivered') {
                statusTick = '<i class="fas fa-check-double status-tick" title="Delivered"></i>';
            } else if (m.status === 'sent') {
                statusTick = '<i class="fas fa-check status-tick" title="Sent"></i>';
            } else if (m.status === 'failed') {
                statusTick = '<i class="fas fa-circle-exclamation status-tick failed" title="Failed"></i>';
            } else {
                statusTick = '<i class="fas fa-clock status-tick" title="Pending"></i>';
            }
        }

        const dateObj = new Date(m.created_at);
        const dateStr = dateObj.toLocaleDateString([], {day:'2-digit', month:'short'});
        const timeStr = dateObj.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
        const dateTimeStr = dateStr + ', ' + timeStr;

        let interactiveBtnHtml = '';
        if (m.message_type === 'interactive' || (m.raw_payload && m.raw_payload.includes('interactive_button_text'))) {
            try {
                const payloadObj = typeof m.raw_payload === 'string' ? JSON.parse(m.raw_payload) : m.raw_payload;
                const btnText = payloadObj.interactive_button_text || 'Message Here';
                const btnUrl = payloadObj.interactive_button_url || 'https://wa.me/917025000444';

                interactiveBtnHtml = `
                    <div style="margin-top: 8px; border-top: 1px dashed rgba(0,0,0,0.1); padding-top: 6px;">
                        <a href="${escapeHtml(btnUrl)}" target="_blank" style="font-size:0.75rem; padding:4px 10px; border-radius:6px; background:#ffffff; display:inline-flex; align-items:center; gap:6px; border:1px solid #cbd5e1; color:#0f172a; text-decoration:none; font-weight:600; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                            <i class="fas fa-arrow-up-right-from-square" style="font-size:0.7rem; color:#2563eb;"></i> ${escapeHtml(btnText)}
                        </a>
                    </div>
                `;
            } catch(e) {}
        }

        let messageContentHtml = `<div style="white-space: pre-wrap;">${escapeHtml(m.message_text)}</div>`;
        if (isFailed) {
            messageContentHtml += `
                <div style="font-size: 0.72rem; color: #b91c1c; background: #fff1f2; border: 1px solid #fda4af; padding: 6px 10px; border-radius: 6px; margin-top: 6px; font-weight: 500; text-align: left;">
                    <strong style="color: #991b1b; display: block; font-weight: 700; margin-bottom: 2px;"><i class="fas fa-circle-exclamation"></i> 🔴 DELIVERY FAILED</strong>
                    Meta: ${escapeHtml(m.failure_reason || 'This message was not delivered to maintain healthy ecosystem engagement.')}
                </div>
            `;
        }
        if (m.message_type === 'image' && m.media_id) {
            const mediaUrl = `api/v1/communication/media.php?id=${m.id}`;
            const downloadUrl = `api/v1/communication/media.php?id=${m.id}&download=1`;
            const captionHtml = m.caption ? `<div style="font-size: 0.8rem; margin-top: 6px; white-space: pre-wrap; color: #334155;">${escapeHtml(m.caption)}</div>` : '';
            messageContentHtml = `
                <div style="display: flex; flex-direction: column; gap: 6px; max-width: 250px;">
                    <img src="${mediaUrl}" alt="Inbound Image" style="width: 100%; max-height: 180px; object-fit: cover; border-radius: 8px; cursor: pointer; border: 1px solid #cbd5e1; background: #f1f5f9;" onclick="openImageLightbox('${mediaUrl}')" onerror="this.onerror=null; this.parentNode.innerHTML='<div style=\'padding: 10px; color: #ef4444; font-size: 0.8rem; font-weight: 600; border: 1px solid #fee2e2; background: #fef2f2; border-radius: 8px; display: flex; align-items: center; gap: 6px;\'><i class=\'fas fa-circle-exclamation\'></i> Image unavailable</div>';">
                    ${captionHtml}
                    <div style="display: flex; gap: 8px; margin-top: 4px; font-size: 0.75rem; border-top: 1px dashed rgba(0,0,0,0.08); padding-top: 4px;">
                        <button class="btn-link" style="color: #6366f1; border: none; background: none; cursor: pointer; padding: 0; font-weight: 600; font-size: 0.75rem; display: inline-flex; align-items: center; gap: 4px;" onclick="openImageLightbox('${mediaUrl}')"><i class="fas fa-expand"></i> View Full Image</button>
                        <span style="color: #cbd5e1;">|</span>
                        <a href="${downloadUrl}" target="_blank" style="color: #6366f1; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 4px;"><i class="fas fa-download"></i> Download</a>
                    </div>
                </div>
            `;
        }

        html += `
            <div class="bubble ${bubbleClass}">
                ${messageContentHtml}
                ${interactiveBtnHtml}
                <div style="display: flex; justify-content: flex-end; align-items: center; font-size: 0.65rem; color: #64748b; margin-top: 4px;">
                    <span>${dateTimeStr}</span>
                    ${statusTick}
                </div>
            </div>
        `;
    });

    container.innerHTML = html;
    if (!isBackground) {
        container.scrollTop = container.scrollHeight;
    }
}

function updateWindowPolicies() {
    const conv = conversationsData.find(c => c.id == currentConversationId);
    if (!conv) return;

    const replyContainer = document.getElementById('reply-container');
    const warning = document.getElementById('twentyfour-hour-warning');
    const freeTextInput = document.getElementById('free-text-input-row');
    const badge = document.getElementById('24h-status-badge');
    const tplPreview = document.getElementById('template-variables-preview');

    replyContainer.style.display = 'flex';
    tplPreview.style.display = 'none';

    if (!conv.last_inbound_at) {
        // No inbound messages yet -> templates only
        warning.style.display = 'flex';
        freeTextInput.style.display = 'none';
        badge.innerHTML = '<span class="badge red" style="font-size:0.7rem; font-weight:700;">TEMPLATES ONLY</span>';
        return;
    }

    const lastInboundTime = new Date(conv.last_inbound_at).getTime();
    const nowTime = new Date().getTime();
    const diffHours = (nowTime - lastInboundTime) / 3600000;

    if (diffHours <= 24) {
        warning.style.display = 'none';
        freeTextInput.style.display = 'flex';
        document.getElementById('reply-textarea').style.display = 'block';
        const sendBtn = document.getElementById('send-reply-btn');
        if (sendBtn) {
            sendBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Send';
            sendBtn.style.background = '';
            sendBtn.style.borderColor = '';
        }

        const hoursLeft = Math.ceil(24 - diffHours);
        badge.innerHTML = `<span class="badge green" style="font-size:0.7rem; font-weight:700;"><i class="fas fa-clock"></i> ${hoursLeft}h left</span>`;
    } else {
        warning.style.display = 'flex';
        freeTextInput.style.display = 'none';
        badge.innerHTML = '<span class="badge red" style="font-size:0.7rem; font-weight:700;">EXPIRED (24h)</span>';
    }
}

function onTemplateSelectChange(tplName) {
    const tplPreview = document.getElementById('template-variables-preview');
    const varList = document.getElementById('variables-list');
    const textPreview = document.getElementById('template-text-preview');
    const freeTextRow = document.getElementById('free-text-input-row');
    const sendBtn = document.getElementById('send-reply-btn');

    if (!tplName) {
        tplPreview.style.display = 'none';
        freeTextRow.style.display = 'none';
        if (sendBtn) {
            sendBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Send';
            sendBtn.style.background = '';
            sendBtn.style.borderColor = '';
        }
        return;
    }

    fetch(`api/v1/communication/resolve-template-preview.php?template_name=${encodeURIComponent(tplName)}&student_uid=${encodeURIComponent(currentStudentUid || '')}`)
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                tplPreview.style.display = 'block';
                freeTextRow.style.display = 'flex'; // show send button row
                document.getElementById('reply-textarea').style.display = 'none'; // hide normal text area since we send template

                if (sendBtn) {
                    sendBtn.innerHTML = '<i class="fab fa-whatsapp"></i> Send Approved Template';
                    sendBtn.style.background = '#10b981';
                    sendBtn.style.borderColor = '#10b981';
                }

                // List parameters and resolved variables (Safeguard 3)
                let varHtml = '';
                res.parameters.forEach((val, idx) => {
                    varHtml += `
                        <div style="display:inline-flex; align-items:center; gap:6px; background:#ffffff; border:1px solid #cbd5e1; border-radius:6px; padding:3px 9px; font-size:0.75rem; box-shadow:0 1px 2px rgba(0,0,0,0.03);">
                            <span style="font-weight:700; color:#4f46e5; background:#e0e7ff; padding:1px 5px; border-radius:4px; font-size:0.7rem;">{{${idx + 1}}}</span>
                            <span style="color:#0f172a; font-weight:600;">${escapeHtml(val)}</span>
                        </div>
                    `;
                });
                varList.innerHTML = varHtml || '<div style="color:#94a3b8; font-style:italic; padding:2px 0;">No variables required for this template.</div>';
                textPreview.innerText = res.preview_body;
            } else {
                alert('Failed to load template variables mapping: ' + res.error);
            }
        });
}

function controlQueueInbox(queueId, action) {
    if (action === 'cancel_queue_item') {
        if (!confirm('Cancel this queue item?\nIt will NOT be sent again automatically.')) {
            return;
        }
    }

    const formData = new FormData();
    formData.append('action', action);
    formData.append('queue_id', queueId);
    formData.append('csrf_token', csrfToken);

    fetch('communication-dashboard.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.text())
    .then(html => {
        loadStudentContext(currentStudentUid);
    });
}

function loadStudentContext(studentUid) {
    const panel = document.getElementById('student-context-panel');
    const container = document.getElementById('student-context-content');

    if (!studentUid) {
        panel.style.display = 'none';
        return;
    }

    panel.style.display = 'flex';

    fetch(`api/v1/communication/fetch-student-details.php?student_uid=${encodeURIComponent(studentUid)}`)
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                const s = res.student;

                let activeQueueHtml = '';
                if (res.active_reminders && res.active_reminders.length > 0) {
                    res.active_reminders.forEach(q => {
                        let btnHtml = '';
                        if (q.status === 'pending' || q.status === 'scheduled') {
                            btnHtml = `
                                <button type="button" class="btn btn-xs" style="padding:2px 4px; font-size:0.65rem; border-radius:3px; background:#f1f5f9; border:1px solid #cbd5e1; cursor:pointer;" onclick="controlQueueInbox(${q.id}, 'pause_queue_item')" title="Pause"><i class="fas fa-pause"></i></button>
                                <button type="button" class="btn btn-xs btn-danger" style="padding:2px 4px; font-size:0.65rem; border-radius:3px; background:#ef4444; border:none; color:#fff; cursor:pointer;" onclick="controlQueueInbox(${q.id}, 'cancel_queue_item')" title="Cancel"><i class="fas fa-xmark"></i></button>
                            `;
                        } else if (q.status === 'paused') {
                            btnHtml = `
                                <button type="button" class="btn btn-xs btn-success" style="padding:2px 4px; font-size:0.65rem; border-radius:3px; color:#fff; background:#10b981; border:none; cursor:pointer;" onclick="controlQueueInbox(${q.id}, 'resume_queue_item')" title="Resume"><i class="fas fa-play"></i></button>
                                <button type="button" class="btn btn-xs btn-danger" style="padding:2px 4px; font-size:0.65rem; border-radius:3px; background:#ef4444; border:none; color:#fff; cursor:pointer;" onclick="controlQueueInbox(${q.id}, 'cancel_queue_item')" title="Cancel"><i class="fas fa-xmark"></i></button>
                            `;
                        } else if (q.status === 'failed') {
                            const isPermanent = isPermanentError(q.error_message);

                            if (isPermanent) {
                                btnHtml = `
                                    <button type="button" class="btn btn-xs" style="padding:2px 4px; font-size:0.65rem; border-radius:3px; background:#e2e8f0; border:none; color:#94a3b8; cursor:not-allowed;" title="Permanent Meta Failure (Cannot Retry)" disabled><i class="fas fa-ban"></i></button>
                                    <button type="button" class="btn btn-xs btn-danger" style="padding:2px 4px; font-size:0.65rem; border-radius:3px; background:#ef4444; border:none; color:#fff; cursor:pointer;" onclick="controlQueueInbox(${q.id}, 'cancel_queue_item')" title="Cancel"><i class="fas fa-xmark"></i></button>
                                `;
                            } else {
                                btnHtml = `
                                    <button type="button" class="btn btn-xs btn-success" style="padding:2px 4px; font-size:0.65rem; border-radius:3px; color:#fff; background:#8b5cf6; border:none; cursor:pointer;" onclick="controlQueueInbox(${q.id}, 'retry_queue_item')" title="Retry"><i class="fas fa-rotate-right"></i></button>
                                    <button type="button" class="btn btn-xs btn-danger" style="padding:2px 4px; font-size:0.65rem; border-radius:3px; background:#ef4444; border:none; color:#fff; cursor:pointer;" onclick="controlQueueInbox(${q.id}, 'cancel_queue_item')" title="Cancel"><i class="fas fa-xmark"></i></button>
                                `;
                            }
                        }

                        activeQueueHtml += `
                            <div style="display:flex; justify-content:space-between; align-items:center; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:6px; margin-bottom:6px; font-size:0.7rem;">
                                <div style="min-width:0; flex:1;">
                                    <div style="font-weight:700; color:#1e293b; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">#${q.id} - ${escapeHtml(q.event_name || 'Manual')}</div>
                                    <div style="color:#64748b; font-size:0.6rem;">Status: <span style="font-weight:700; color:${q.status === 'paused' ? '#d97706' : (q.status === 'failed' ? '#ef4444' : '#2563eb')}">${q.status.toUpperCase()}</span></div>
                                </div>
                                <div style="display:flex; gap:4px; margin-left:6px;">
                                    ${btnHtml}
                                </div>
                            </div>
                        `;
                    });
                } else {
                    activeQueueHtml = '<div style="font-size:0.75rem; color:#94a3b8; text-align:center; padding:8px;">No active queue items.</div>';
                }

                container.innerHTML = `
                    <div><strong>Name:</strong> <div>${escapeHtml(s.name)}</div></div>
                    <div><strong>Student UID:</strong> <div>${escapeHtml(s.user_id)}</div></div>
                    <div><strong>Course:</strong> <div>${escapeHtml(s.pepp_course)}</div></div>
                    <div><strong>Academic Year:</strong> <div>${escapeHtml(s.pepp_academic_year)}</div></div>
                    <div><strong>Admission Status:</strong> <span class="badge ${s.status === 'approved' ? 'green' : 'orange'}">${s.status.toUpperCase()}</span></div>
                    <div><strong>Total Course Fee:</strong> <div>₹${s.total_fee}</div></div>
                    <div><strong>Total Paid:</strong> <div>₹${s.total_paid}</div></div>
                    <div><strong>Outstanding Balance:</strong> <div style="color:#ef4444; font-weight:700;">₹${s.balance}</div></div>
                    <div><strong>Next Due Date:</strong> <div style="font-weight:700; color:#1e293b;">${s.next_due_date}</div></div>

                    <div style="margin-top:16px; border-top:1px solid #e2e8f0; padding-top:12px;">
                        <strong style="display:block; margin-bottom:8px; font-size:0.8rem; color:#475569;"><i class="fas fa-list-check"></i> Active Queue Items</strong>
                        ${activeQueueHtml}
                    </div>

                    <a href="student-details.php?id=${s.id}" class="btn btn-outline" style="text-align:center; padding:6px; font-size:0.75rem; border-radius:6px; margin-top:8px;" target="_blank">
                        <i class="fas fa-external-link"></i> Go to Profile
                    </a>
                `;
            } else {
                container.innerHTML = '<div style="color:#ef4444;">Failed to load context.</div>';
            }
        });
}

function sendReply() {
    const text = document.getElementById('reply-textarea').value;
    const tplSelect = document.getElementById('template-select');
    const templateName = tplSelect ? tplSelect.value : '';

    if (!templateName && !text.trim()) {
        return;
    }

    const payload = {
        conversation_id: currentConversationId,
        message_text: templateName ? '' : text,
        template_name: templateName
    };

    fetch('api/v1/communication/send-reply.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
        },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(res => {
        if (res.success) {
            // Reset input values
            document.getElementById('reply-textarea').value = '';
            document.getElementById('reply-textarea').style.display = 'block';
            if (tplSelect) tplSelect.value = '';
            document.getElementById('template-variables-preview').style.display = 'none';
            const sendBtn = document.getElementById('send-reply-btn');
            if (sendBtn) {
                sendBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Send';
                sendBtn.style.background = '';
                sendBtn.style.borderColor = '';
            }

            // Reload message thread immediately
            loadMessages(currentConversationId);
            loadConversations();
        } else {
            alert('Failed to send message: ' + res.error);
        }
    });
}

function adjustTextareaHeight(el) {
    el.style.height = 'auto';
    el.style.height = (el.scrollHeight) + 'px';
}

function escapeHtml(text) {
    if (!text) return '';
    return text
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function openImageLightbox(url) {
    document.getElementById('lightbox-image').src = url;
    document.getElementById('lightbox-download').href = url + '&download=1';
    document.getElementById('image-lightbox-modal').style.display = 'flex';
}
function closeImageLightbox() {
    document.getElementById('image-lightbox-modal').style.display = 'none';
    document.getElementById('lightbox-image').src = '';
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeImageLightbox();
    }
});
</script>

<?php include 'includes/admin_footer.php'; ?>

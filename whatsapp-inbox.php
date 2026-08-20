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
            <div id="twentyfour-hour-warning" style="display: none; background: #fffbeb; border: 1px solid #fef3c7; border-radius: 8px; padding: 10px; font-size: 0.75rem; color: #b45309; align-items: center; gap: 8px;">
                <i class="fas fa-triangle-exclamation"></i>
                <div style="flex:1;">The 24-hour response window has expired. You can only send approved templates.</div>
                <select id="template-select" onchange="onTemplateSelectChange(this.value)" style="padding: 4px 8px; border-radius: 6px; border: 1px solid #cbd5e1; outline: none; font-size: 0.75rem;">
                    <option value="">- Select Approved Template -</option>
                    <?php foreach ($approvedTemplates as $tplName): ?>
                        <option value="<?php echo htmlspecialchars($tplName); ?>"><?php echo htmlspecialchars($tplName); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <!-- Template Variables Mapping Visualizer (Safeguard 3) -->
            <div id="template-variables-preview" style="display: none; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; font-size: 0.75rem;">
                <h5 style="margin:0 0 6px; font-weight:700; color:#475569;">Variables Mapping &amp; Live Resolution:</h5>
                <div id="variables-list" style="display:flex; flex-direction:column; gap:4px; margin-bottom:8px;"></div>
                <h5 style="margin:0 0 4px; font-weight:700; color:#475569;">Message Preview:</h5>
                <div id="template-text-preview" style="background:#e0f2fe; color:#0369a1; padding:8px; border-radius:6px; font-family:monospace; white-space:pre-wrap;"></div>
            </div>

            <!-- Free text message inputs -->
            <div style="display: flex; gap: 10px; align-items: flex-end;" id="free-text-input-row">
                <textarea id="reply-textarea" placeholder="Type a message..." rows="1" style="flex: 1; border: 1px solid #cbd5e1; border-radius: 8px; padding: 8px 12px; font-size: 0.85rem; outline: none; resize: none; min-height: 38px; max-height: 120px;" oninput="adjustTextareaHeight(this)"></textarea>
                <button onclick="sendReply()" class="btn btn-primary" style="padding: 9px 18px; border-radius: 8px; display: flex; align-items: center; gap: 6px; height: 38px;">
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
</style>

<script>
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
                            <span style="font-weight: 700; color: #1e293b; font-size: 0.85rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${c.contact_name || 'Unknown Contact'}</span>
                            <span style="font-size: 0.65rem; color: #94a3b8; white-space: nowrap;">${dateTimeStr}</span>
                        </div>
                        <div style="display: flex; align-items: center; justify-content: space-between; gap:4px; margin-top: 2px;">
                            <span style="font-size: 0.75rem; color: #64748b; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${lastMsgSnippet}</span>
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
        const bubbleClass = isOut ? 'outbound' : 'inbound';
        
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

        html += `
            <div class="bubble ${bubbleClass}">
                <div style="white-space: pre-wrap;">${escapeHtml(m.message_text)}</div>
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

    if (!tplName) {
        tplPreview.style.display = 'none';
        freeTextRow.style.display = 'none';
        return;
    }

    fetch(`api/v1/communication/resolve-template-preview.php?template_name=${encodeURIComponent(tplName)}&student_uid=${encodeURIComponent(currentStudentUid || '')}`)
        .then(r => r.json())
        .then(res => {
            if (res.success) {
                tplPreview.style.display = 'block';
                freeTextRow.style.display = 'flex'; // show send button row
                document.getElementById('reply-textarea').style.display = 'none'; // hide normal text area since we send template

                // List parameters and resolved variables (Safeguard 3)
                let varHtml = '';
                res.parameters.forEach((val, idx) => {
                    varHtml += `<div><strong>{{${idx + 1}}}:</strong> <span style="color:#059669;">${escapeHtml(val)}</span></div>`;
                });
                varList.innerHTML = varHtml || '<div style="color:#94a3b8;">No parameters required.</div>';
                textPreview.innerText = res.preview_body;
            } else {
                alert('Failed to load template variables mapping: ' + res.error);
            }
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
</script>

<?php include 'includes/admin_footer.php'; ?>
